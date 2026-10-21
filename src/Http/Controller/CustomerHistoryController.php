<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Identity\Entity\User;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CustomerHistoryController extends AbstractController
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 1000;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/customers/{id}/history', name: 'api_customer_history', methods: ['GET'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['page']) !== []) {
            throw new BadRequestHttpException('Parámetros de consulta no admitidos.');
        }
        $rawPage = $query['page'] ?? '1';
        if (
            !is_string($rawPage)
            || preg_match('/^[1-9][0-9]{0,3}$/D', $rawPage) !== 1
            || (int) $rawPage > self::MAX_PAGE
        ) {
            throw new BadRequestHttpException('Página no válida.');
        }
        $page = (int) $rawPage;

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        try {
            $tenant = $this->currentTenantForUser->resolve($user);
            $membership = $this->authorization->membership($user, $tenant);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        // A global URL must not grant a delegated account access to other branches.
        $authorizedBranches = [];
        $branches = $this->entityManager->getRepository(Branch::class)
            ->findBy(['tenant' => $tenant]);
        foreach ($branches as $branch) {
            if (
                $branch instanceof Branch
                && in_array(
                    'customers.view',
                    $this->authorization->permissions($user, $tenant, $branch),
                    true,
                )
            ) {
                $authorizedBranches[] = $branch;
            }
        }
        if ($authorizedBranches === []) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar clientes.');
        }

        $customer = $this->entityManager->getRepository(Customer::class)
            ->findOneBy(['id' => $id, 'tenant' => $tenant, 'active' => true]);
        if (!$customer instanceof Customer) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        $isOwner = $this->authorization->isOwner($membership);
        $base = $this->orderQuery($tenant, $customer, $isOwner ? null : $authorizedBranches);
        $rows = (clone $base)
            ->select('o')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE + 1)
            ->getQuery()->getResult();
        $hasNext = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        $aggregates = (clone $base)
            ->select(
                'o.currency AS currency',
                'COUNT(o.id) AS order_count',
                'SUM(CASE WHEN o.orderStatus = :cancelled THEN 0 ELSE o.totalAmountMinor END) AS purchased_minor',
            )
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->groupBy('o.currency')
            ->getQuery()->getArrayResult();
        $count = 0;
        $totals = [];
        foreach ($aggregates as $group) {
            $count += (int) $group['order_count'];
            // Decimal strings avoid floating-point rounding and mixed-currency sums.
            $totals[(string) $group['currency']] = (string) $group['purchased_minor'];
        }
        ksort($totals, SORT_STRING);

        $last = (clone $base)
            ->select('o')
            ->andWhere('o.orderStatus != :cancelled')
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $response = $this->json([
            'customer_id' => $customer->id(),
            'orders' => array_values(array_map(
                static fn (Order $order): array => [
                    'id' => $order->id(),
                    'status' => $order->orderStatus(),
                    'payment_status' => $order->paymentStatus(),
                    'currency' => $order->currency(),
                    'total_amount_minor' => $order->totalAmountMinor(),
                    'created_at' => $order->createdAt()->format(DATE_ATOM),
                ],
                $rows,
            )),
            'summary' => [
                'order_count' => $count,
                'total_purchased_by_currency_minor' => $totals,
                'last_purchase_at' => $last instanceof Order
                    ? $last->createdAt()->format(DATE_ATOM)
                    : null,
            ],
            'pagination' => [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'has_previous' => $page > 1,
                'has_next' => $hasNext,
            ],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param null|list<Branch> $allowedBranches Null means tenant owner.
     */
    private function orderQuery(
        Tenant $tenant,
        Customer $customer,
        ?array $allowedBranches,
    ): QueryBuilder {
        $query = $this->entityManager->createQueryBuilder()
            ->from(Order::class, 'o')
            ->where('o.tenant = :tenant')
            ->andWhere('o.customer = :customer')
            ->setParameter('tenant', $tenant)
            ->setParameter('customer', $customer);

        if ($allowedBranches !== null) {
            $query->innerJoin('o.inventorySource', 'source')
                ->andWhere('source.tenant = :tenant')
                ->andWhere('source.branch IN (:branches)')
                ->setParameter('branches', $allowedBranches);
        }

        return $query;
    }
}

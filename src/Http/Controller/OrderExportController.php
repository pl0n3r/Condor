<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Orders\OrderCsvExporter;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/** Session-scoped, read-only export; no tenant or source selector from the request. */
final class OrderExportController extends AbstractController
{
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderCsvExporter $csv,
    ) {
    }

    #[Route('/api/orders/export.csv', name: 'api_orders_csv_export', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Se requiere una sesión autenticada.');
        }
        $params = $request->query->all();
        if (array_diff(array_keys($params), ['from', 'to']) !== []
            || !is_string($params['from'] ?? null)
            || !is_string($params['to'] ?? null)) {
            throw new BadRequestHttpException('Se requieren from y to como fechas sin campos adicionales.');
        }
        $from = $params['from'];
        $to = $params['to'];
        try {
            [$start, $end] = $this->csv->window($from, $to);
        } catch (DomainException $exception) {
            throw new BadRequestHttpException('El rango de fechas no es válido.', $exception);
        }

        try {
            $tenant = $this->currentTenantForUser->resolve($user);
            $membership = $this->authorization->membership($user, $tenant);
            if (!$this->authorization->hasAnyPermission($user, $tenant, ['orders.view'])) {
                throw new AccessDeniedHttpException('No tienes permiso para exportar pedidos.');
            }
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('No tienes acceso a la empresa activa.', $exception);
        }

        // A delegated user sees only branch stock sources with the required branch permission.
        // Logical sources without an attributable branch are owner-only: never infer access.
        try {
            $sources = $this->entityManager->getRepository(InventorySource::class)
                ->findBy(['tenant' => $tenant, 'active' => true]);
            $authorizedSources = [];
            foreach ($sources as $source) {
                if (!$source instanceof InventorySource) {
                    continue;
                }
                if ($membership->roleKey() === Membership::ROLE_OWNER) {
                    $authorizedSources[] = $source;
                    continue;
                }
                $branch = $source->branch();
                if ($branch !== null
                    && in_array('orders.view', $this->authorization->permissions($user, $tenant, $branch), true)) {
                    $authorizedSources[] = $source;
                }
            }
            if ($authorizedSources === []) {
                throw new AccessDeniedHttpException('No hay sedes autorizadas para exportación.');
            }

            $orders = $this->entityManager->getRepository(Order::class)
                ->createQueryBuilder('o')
                ->where('o.tenant = :tenant')
                ->andWhere('o.inventorySource IN (:sources)')
                ->andWhere('o.createdAt >= :start AND o.createdAt < :end')
                ->setParameter('tenant', $tenant)
                ->setParameter('sources', $authorizedSources)
                ->setParameter('start', $start)
                ->setParameter('end', $end)
                ->orderBy('o.createdAt', 'ASC')
                ->addOrderBy('o.id', 'ASC')
                ->setMaxResults(OrderCsvExporter::ROW_LIMIT + 1)
                ->getQuery()->getResult();
            if (count($orders) > OrderCsvExporter::ROW_LIMIT) {
                throw new DomainException('ORDER_CSV_ROW_LIMIT');
            }
            $rows = [];
            foreach ($orders as $order) {
                if (!$order instanceof Order) {
                    throw new DomainException('ORDER_CSV_INVALID_RECORD');
                }
                $rows[] = [
                    'tenant_id' => $order->tenant()->id(),
                    'source_id' => $order->inventorySource()->id(),
                    'id' => $order->id(),
                    'created_at' => $order->createdAt(),
                    'order_status' => $order->orderStatus(),
                    'payment_status' => $order->paymentStatus(),
                    'fulfillment_status' => $order->fulfillmentStatus(),
                    'customer_name' => $order->customer()?->name() ?? '',
                    'currency' => $order->currency(),
                    'total_amount_minor' => $order->totalAmountMinor(),
                ];
            }
            $body = $this->csv->build(
                $tenant->id(),
                array_map(static fn (InventorySource $s): string => $s->id(), $authorizedSources),
                $from,
                $to,
                $rows,
            );
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never disclose rows, SQL or account identifiers through an error message.
            throw new ServiceUnavailableHttpException(null, 'Exportación de pedidos no disponible.');
        }

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="pedidos-'.$from.'-'.$to.'.csv"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

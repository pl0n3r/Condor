<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Orders\OrderPaymentService;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OrderPaymentController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPaymentService $payments,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders/{orderId}/payments',
        name: 'api_order_payments_create',
        methods: ['POST'],
    )]
    public function create(
        string $branchId,
        string $orderId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.update');
        $order = $this->scopedOrder($tenant, $branch, $orderId);
        $payload = $this->payload($request, [
            'amount_minor', 'method', 'idempotency_key',
        ]);
        if (!is_int($payload['amount_minor'] ?? null)
            || !is_string($payload['method'] ?? null)
            || !is_string($payload['idempotency_key'] ?? null)) {
            throw new UnprocessableEntityHttpException('Campos de pago inválidos.');
        }
        $user = $this->getUser();
        if (!$user instanceof \App\Domain\Identity\Entity\User) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }
        $result = $this->domain(fn (): array => $this->payments->record(
            $order,
            $tenant->id(),
            $user->id(),
            $payload['amount_minor'],
            $payload['method'],
            $payload['idempotency_key'],
        ));

        return $this->json([
            'order_id' => $order->id(),
            'currency' => $order->currency(),
            'payment' => $result,
        ], $result['idempotent'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders/{orderId}/payments',
        name: 'api_order_payments_balance',
        methods: ['GET'],
    )]
    public function show(string $branchId, string $orderId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.view');
        $order = $this->scopedOrder($tenant, $branch, $orderId);
        return $this->json([
            'order_id' => $order->id(),
            'currency' => $order->currency(),
            'payment' => $this->domain(
                fn (): array => $this->payments->balance($order, $tenant->id()),
            ),
        ]);
    }

    /** A branch URL never authorizes paying an order from another branch. */
    private function scopedOrder(Tenant $tenant, Branch $branch, string $orderId): Order
    {
        $legalEntity = $branch->legalEntity();
        if ($legalEntity === null) {
            throw new ConflictHttpException('Sede sin entidad legal.');
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'id' => $orderId,
            'tenant' => $tenant,
            'legalEntity' => $legalEntity,
        ]);
        if (!$order instanceof Order
            || $order->inventorySource()->branch()?->id() !== $branch->id()) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }
        return $order;
    }
}

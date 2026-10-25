<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Orders\OrderRefundService;
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

/** Refund only recorded manual payments; authorisation precedes all ledger access. */
final class OrderRefundController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRefundService $refunds,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders/{orderId}/refunds',
        name: 'api_order_refunds_create', methods: ['POST'],
    )]
    public function create(string $branchId, string $orderId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.update');
        $order = $this->scopedOrder($tenant, $branch, $orderId);
        $data = $this->payload($request, [
            'payment_key', 'amount_minor', 'reason', 'idempotency_key',
            'restock_line_id', 'restock_quantity',
        ]);
        if (!is_string($data['payment_key'] ?? null)
            || !is_int($data['amount_minor'] ?? null)
            || !is_string($data['reason'] ?? null)
            || !is_string($data['idempotency_key'] ?? null)
            || (isset($data['restock_line_id']) && !is_string($data['restock_line_id']))
            || (isset($data['restock_quantity']) && !is_int($data['restock_quantity']))) {
            throw new UnprocessableEntityHttpException('Campos de devolución inválidos.');
        }
        $user = $this->getUser();
        if (!$user instanceof \App\Domain\Identity\Entity\User) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }
        $result = $this->domain(fn (): array => $this->refunds->refund(
            $order, $tenant->id(), $user->id(), $data['payment_key'],
            $data['amount_minor'], $data['reason'], $data['idempotency_key'],
            $data['restock_line_id'] ?? null, $data['restock_quantity'] ?? null,
        ));
        return $this->json([
            'order_id' => $order->id(), 'currency' => $order->currency(),
            'refund' => $result,
        ], $result['idempotent'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    private function scopedOrder(Tenant $tenant, Branch $branch, string $orderId): Order
    {
        $legalEntity = $branch->legalEntity();
        if ($legalEntity === null) {
            throw new ConflictHttpException('Sede sin entidad legal.');
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'id' => $orderId, 'tenant' => $tenant, 'legalEntity' => $legalEntity,
        ]);
        if (!$order instanceof Order
            || $order->inventorySource()->branch()?->id() !== $branch->id()) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }
        return $order;
    }
}

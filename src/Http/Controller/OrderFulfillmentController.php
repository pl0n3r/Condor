<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Orders\OrderFulfillmentService;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OrderFulfillmentController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderFulfillmentService $fulfillment,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/orders/{orderId}/delivery',
        name: 'api_order_delivery_show', methods: ['GET'])]
    public function show(string $branchId, string $orderId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.view');

        return $this->respond($this->fulfillment->current($this->order($tenant, $branch, $orderId)));
    }

    #[Route('/api/v1/branches/{branchId}/orders/{orderId}/delivery',
        name: 'api_order_delivery_configure', methods: ['POST'])]
    public function configure(string $branchId, string $orderId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.update');
        $data = $this->payload($request, ['method', 'tracking_number']);
        if (!is_string($data['method'] ?? null)
            || (isset($data['tracking_number']) && !is_string($data['tracking_number']))) {
            throw new UnprocessableEntityHttpException('Datos de entrega inválidos.');
        }

        return $this->respond($this->domain(fn (): array => $this->fulfillment->configure(
            $this->order($tenant, $branch, $orderId), $tenant->id(), $actor->id(),
            $data['method'], $data['tracking_number'] ?? null,
        )));
    }

    #[Route('/api/v1/branches/{branchId}/orders/{orderId}/delivery/transition',
        name: 'api_order_delivery_transition', methods: ['POST'])]
    public function transition(string $branchId, string $orderId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.update');
        $data = $this->payload($request, ['status']);
        if (!is_string($data['status'] ?? null)) {
            throw new UnprocessableEntityHttpException('Estado de entrega inválido.');
        }

        return $this->respond($this->domain(fn (): array => $this->fulfillment->transition(
            $this->order($tenant, $branch, $orderId), $tenant->id(), $actor->id(), $data['status'],
        )));
    }

    private function order(Tenant $tenant, Branch $branch, string $orderId): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'id' => $orderId, 'tenant' => $tenant, 'legalEntity' => $branch->legalEntity(),
        ]);
        if (!$order instanceof Order || $order->inventorySource()->branch()?->id() !== $branch->id()) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }

        return $order;
    }

    /** @param array<string, mixed> $delivery */
    private function respond(array $delivery): JsonResponse
    {
        $response = $this->json(['delivery' => $delivery]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

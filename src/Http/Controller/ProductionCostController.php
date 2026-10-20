<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\ProductionCostService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Production\Entity\ProductionOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consulta de costo persistido de una orden terminada, por tenant y sede.
 * Jamás reconstruir un precio histórico al consultar: las órdenes antiguas
 * sin comprobante guardado siguen teniendo costo UNKNOWN.
 */
final class ProductionCostController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductionCostService $productionCosts,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/orders/{orderId}/cost',
        name: 'api_production_order_cost',
        methods: ['GET'],
    )]
    public function cost(string $branchId, string $orderId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.view',
        );

        $source = $this->entityManager
            ->getRepository(InventorySource::class)
            ->findOneBy(['tenant' => $tenant, 'branch' => $branch]);
        if (
            !$source instanceof InventorySource
            || $source->type() !== InventorySource::TYPE_BRANCH
            || !$source->isActive()
        ) {
            throw new NotFoundHttpException('Fuente de inventario no encontrada.');
        }

        // Un ULID de otro tenant o sede jamás revela si la orden existe.
        $order = $this->entityManager
            ->getRepository(ProductionOrder::class)
            ->findOneBy([
                'id' => $orderId,
                'tenant' => $tenant,
                'source' => $source,
            ]);
        if (!$order instanceof ProductionOrder) {
            throw new NotFoundHttpException('Orden de producción no encontrada.');
        }
        if (!$order->isCompleted() || $order->completedAt() === null) {
            throw new UnprocessableEntityHttpException(
                'El costo solo está disponible para órdenes terminadas.',
            );
        }

        // #738 ya está integrado: solo existe la lectura del snapshot guardado.
        $saved = $order->costSnapshot();
        if ($saved === null) {
            return $this->unknownCost($order->id());
        }
        if (
            ($saved['source_id'] ?? null) !== $source->id()
        ) {
            throw new UnprocessableEntityHttpException(
                'El comprobante de costo guardado no es verificable.',
            );
        }

        // Validar contra tenant, orden, BOM, cantidades, precios y totales
        // usando el comprobante inmutable: cero consultas a precios actuales.
        $verified = $this->domain(fn (): array => $this->productionCosts->calculate(
            $tenant,
            $order,
            [],
            $saved,
        ));

        return $this->json([
            'order_id' => $order->id(),
            'state' => 'ESTIMATED_COST',
            'cost' => $verified,
            'provenance' => 'production_completion_snapshot',
            'snapshot_persisted' => true,
        ]);
    }

    private function unknownCost(string $orderId): JsonResponse
    {
        return $this->json([
            'order_id' => $orderId,
            'state' => 'UNKNOWN',
            'cost' => null,
            'provenance' => 'none',
            'snapshot_persisted' => false,
        ]);
    }
}

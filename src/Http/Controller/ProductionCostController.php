<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\ProductionCostService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lectura de costo de una orden terminada dentro de su tenant y sede.
 * No confunde un costo histórico reconstruido con un snapshot persistido:
 * la escritura al completar la orden aún necesita un contrato propio.
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

        // El ULID ajeno no debe revelar si existe la orden en otro tenant o sede.
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

        // Recibos posteriores no deben revalorar una orden ya terminada.
        // La compra recibida es inmutable según el contrato del dominio.
        // Esta reconstrucción NO es todavía un snapshot guardado.
        $receipts = $this->entityManager
            ->getRepository(PurchaseOrder::class)
            ->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.legalEntity = :legalEntity')
            ->andWhere('p.status = :received')
            ->andWhere('p.receivedAt <= :completion')
            ->setParameter('tenant', $tenant)
            ->setParameter('legalEntity', $source->legalEntity())
            ->setParameter('received', PurchaseOrder::STATUS_RECEIVED)
            ->setParameter('completion', $order->completedAt())
            ->getQuery()
            ->getResult();

        $snapshot = $this->domain(fn (): array => $this->productionCosts->calculate(
            $tenant,
            $order,
            $receipts,
        ));

        return $this->json([
            'order_id' => $order->id(),
            'cost' => $snapshot,
            'provenance' => 'historical_receipts_as_of_completion',
            'snapshot_persisted' => false,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Inventory\InventoryService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Catalog\Entity\ProductVariant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class ProductionOrderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MaterialInventoryService $materialInventory,
        private InventoryService $inventory,
    ) {
    }

    public function create(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
        BillOfMaterials $billOfMaterials,
        ProductVariant $variant,
        InventorySource $source,
        int $targetQuantity,
    ): ProductionOrder {
        self::assertEntitledTenant($tenant, $entitlements);
        self::assertScope(
            $tenant,
            $billOfMaterials,
            $variant,
            $source,
            true,
        );

        return $this->entityManager->wrapInTransaction(
            function () use (
                $tenant,
                $billOfMaterials,
                $variant,
                $source,
                $targetQuantity,
            ): ProductionOrder {
                $order = new ProductionOrder(
                    $tenant,
                    $billOfMaterials,
                    $variant,
                    $source,
                    $targetQuantity,
                );
                $this->entityManager->persist($order);

                return $order;
            },
        );
    }

    public function complete(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
        ProductionOrder $order,
        int $completedQuantity,
        string $idempotencyKey,
        ?string $actorUserId = null,
    ): ProductionOrder {
        self::assertEntitledTenant($tenant, $entitlements);
        $key = self::normalizeRootKey($idempotencyKey);

        try {
            return $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use (
                    $tenant,
                    $order,
                    $completedQuantity,
                    $key,
                    $actorUserId,
                ): ProductionOrder {
                    $entityManager->refresh(
                        $order,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    self::assertOwnedOrder($tenant, $order);

                    if ($order->isCompleted()) {
                        $order->assertSameCompletion(
                            $completedQuantity,
                            $key,
                        );

                        return $order;
                    }

                    $existing = $entityManager
                        ->getRepository(ProductionOrder::class)
                        ->findOneBy([
                            'tenant' => $tenant,
                            'completionIdempotencyKey' => $key,
                        ]);
                    if (
                        $existing instanceof ProductionOrder
                        && $existing->id() !== $order->id()
                    ) {
                        throw new DomainException(
                            'La clave de completion ya fue usada por otra orden.',
                        );
                    }

                    $source = $order->source();
                    $variant = $order->variant();
                    $entityManager->refresh(
                        $source,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    $entityManager->refresh(
                        $variant->product(),
                        LockMode::PESSIMISTIC_READ,
                    );
                    $entityManager->refresh(
                        $variant,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    self::assertScope(
                        $tenant,
                        $order->billOfMaterials(),
                        $variant,
                        $source,
                        false,
                    );
                    self::assertCompletionQuantity(
                        $order,
                        $completedQuantity,
                    );

                    $requirements = [];
                    foreach ($order->billOfMaterials()->lines() as $line) {
                        $requirements[] = [
                            'material' => $line->material(),
                            'quantity' => MaterialInventoryBalance::multiply(
                                $line->quantity(),
                                $completedQuantity,
                            ),
                        ];
                    }

                    $this->materialInventory->consumeForProduction(
                        $tenant,
                        $source,
                        $requirements,
                        $key,
                        $order->id(),
                        $actorUserId,
                    );
                    $this->inventory->adjust(
                        $tenant,
                        $source,
                        $variant,
                        $completedQuantity,
                        $actorUserId,
                        self::finishedKey($key),
                        [
                            'production_order_id' => $order->id(),
                            'bom_id' => $order->billOfMaterials()->id(),
                            'bom_version' => $order->billOfMaterials()->version(),
                        ],
                    );

                    $order->complete($completedQuantity, $key);

                    return $order;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'La completion de producción entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    private static function assertEntitledTenant(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
    ): void {
        if ($entitlements->tenantId() !== $tenant->id()) {
            throw new DomainException(
                'El entitlement pertenece a otro tenant.',
            );
        }

        try {
            $enabled = $entitlements->addOn('production-lite');
        } catch (DomainException $exception) {
            throw new DomainException(
                'Producción Lite no está habilitada para este tenant.',
                0,
                $exception,
            );
        }
        if ($enabled !== true) {
            throw new DomainException(
                'Producción Lite no está habilitada para este tenant.',
            );
        }
    }

    private static function assertOwnedOrder(
        Tenant $tenant,
        ProductionOrder $order,
    ): void {
        if ($order->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La orden de producción pertenece a otro tenant.',
            );
        }
    }

    private static function assertScope(
        Tenant $tenant,
        BillOfMaterials $billOfMaterials,
        ProductVariant $variant,
        InventorySource $source,
        bool $requireActiveBom,
    ): void {
        if (
            $billOfMaterials->tenant()->id() !== $tenant->id()
            || $variant->tenant()->id() !== $tenant->id()
            || $source->tenant()->id() !== $tenant->id()
            || $billOfMaterials->variant()->id() !== $variant->id()
        ) {
            throw new DomainException(
                'La BOM, variante y fuente deben pertenecer al tenant y producto de la orden.',
            );
        }
        if ($requireActiveBom && !$billOfMaterials->isActive()) {
            throw new DomainException(
                'Una orden nueva requiere una BOM activa.',
            );
        }
        if (!$source->isActive()) {
            throw new DomainException(
                'La fuente de producción debe estar activa.',
            );
        }
        if (!$variant->isActive() || !$variant->product()->isActive()) {
            throw new DomainException(
                'La variante terminada debe estar activa.',
            );
        }
    }

    private static function assertCompletionQuantity(
        ProductionOrder $order,
        int $completedQuantity,
    ): void {
        if ($completedQuantity !== $order->targetQuantity()) {
            throw new DomainException(
                'Producción Lite no admite consumo o cierre parcial.',
            );
        }
    }

    private static function normalizeRootKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 80) {
            throw new DomainException(
                'La clave raíz de completion admite máximo 80 caracteres.',
            );
        }

        return $key;
    }

    private static function finishedKey(string $root): string
    {
        return $root.':finished';
    }
}

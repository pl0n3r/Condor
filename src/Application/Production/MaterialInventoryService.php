<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\MaterialInventoryMovement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class MaterialInventoryService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function adjust(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
        string $delta,
        ?string $actorUserId = null,
        ?string $idempotencyKey = null,
        array $context = [],
    ): MaterialInventoryMovement {
        $delta = MaterialInventoryBalance::normalizeDelta($delta);
        $key = $idempotencyKey === null
            ? null
            : MaterialInventoryMovement::normalizeIdempotencyKey($idempotencyKey);

        try {
            return $this->transactional(
                function (EntityManagerInterface $entityManager) use (
                    $tenant,
                    $source,
                    $material,
                    $delta,
                    $actorUserId,
                    $key,
                    $context,
                ): MaterialInventoryMovement {
                    self::assertOwnedScope($tenant, $source, $material);
                    $this->lockScope($entityManager, $source, [$material]);

                    if ($key !== null) {
                        $existing = $entityManager
                            ->getRepository(MaterialInventoryMovement::class)
                            ->findOneBy([
                                'tenant' => $tenant,
                                'idempotencyKey' => $key,
                            ]);
                        if ($existing instanceof MaterialInventoryMovement) {
                            self::assertSameMovement(
                                $existing,
                                $source,
                                $material,
                                $delta,
                                $context,
                            );

                            return $existing;
                        }
                    }

                    self::assertActiveScope($source, $material);
                    $balance = $this->balanceForUpdate(
                        $entityManager,
                        $tenant,
                        $source,
                        $material,
                    );
                    $balance->apply($delta);

                    $movement = new MaterialInventoryMovement(
                        $tenant,
                        $source,
                        $material,
                        str_starts_with($delta, '-')
                            ? MaterialInventoryMovement::TYPE_ADJUSTMENT_OUT
                            : MaterialInventoryMovement::TYPE_ADJUSTMENT_IN,
                        $delta,
                        $balance->quantity(),
                        $actorUserId,
                        $key,
                        $context,
                    );
                    $entityManager->persist($movement);

                    return $movement;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'El ajuste de material entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param list<array{material: Material, quantity: string}> $requirements
     * @return list<MaterialInventoryMovement>
     */
    public function consumeForProduction(
        Tenant $tenant,
        InventorySource $source,
        array $requirements,
        string $idempotencyRoot,
        string $productionOrderId,
        ?string $actorUserId = null,
    ): array {
        $root = self::normalizeRootKey($idempotencyRoot);
        if ($requirements === []) {
            throw new DomainException(
                'La orden requiere al menos un material para consumir.',
            );
        }

        /** @var array<string,array{material: Material, quantity: string}> $normalized */
        $normalized = [];
        foreach ($requirements as $requirement) {
            $material = $requirement['material'] ?? null;
            $quantity = $requirement['quantity'] ?? null;
            if (!$material instanceof Material || !is_string($quantity)) {
                throw new DomainException(
                    'Cada consumo requiere material y cantidad decimal canónica.',
                );
            }
            self::assertOwnedScope($tenant, $source, $material);
            if (isset($normalized[$material->id()])) {
                throw new DomainException(
                    'Un material no puede repetirse en un consumo de producción.',
                );
            }

            $quantity = MaterialInventoryBalance::normalizeQuantity($quantity);
            if ($quantity === '0') {
                throw new DomainException(
                    'El consumo de material debe ser mayor que cero.',
                );
            }
            $normalized[$material->id()] = [
                'material' => $material,
                'quantity' => $quantity,
            ];
        }
        ksort($normalized);

        try {
            return $this->transactional(
                function (EntityManagerInterface $entityManager) use (
                    $tenant,
                    $source,
                    $normalized,
                    $root,
                    $productionOrderId,
                    $actorUserId,
                ): array {
                    $materials = array_map(
                        static fn (array $item): Material => $item['material'],
                        array_values($normalized),
                    );
                    $this->lockScope($entityManager, $source, $materials);
                    self::assertSourceActive($source);

                    $movements = [];
                    foreach ($normalized as $item) {
                        $material = $item['material'];
                        self::assertMaterialActive($material);
                        $quantity = $item['quantity'];
                        $delta = '-'.$quantity;
                        $key = self::derivedMaterialKey($root, $material);
                        $context = ['production_order_id' => $productionOrderId];

                        $existing = $entityManager
                            ->getRepository(MaterialInventoryMovement::class)
                            ->findOneBy([
                                'tenant' => $tenant,
                                'idempotencyKey' => $key,
                            ]);
                        if ($existing instanceof MaterialInventoryMovement) {
                            self::assertSameMovement(
                                $existing,
                                $source,
                                $material,
                                $delta,
                                $context,
                            );
                            $movements[] = $existing;

                            continue;
                        }

                        $balance = $this->balanceForUpdate(
                            $entityManager,
                            $tenant,
                            $source,
                            $material,
                        );
                        $balance->apply($delta);
                        $movement = new MaterialInventoryMovement(
                            $tenant,
                            $source,
                            $material,
                            MaterialInventoryMovement::TYPE_PRODUCTION_CONSUMPTION,
                            $delta,
                            $balance->quantity(),
                            $actorUserId,
                            $key,
                            $context,
                        );
                        $entityManager->persist($movement);
                        $movements[] = $movement;
                    }

                    return $movements;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'El consumo de materiales entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    /**
     * @template T
     * @param callable(EntityManagerInterface): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            return $operation($this->entityManager);
        }

        return $this->entityManager->wrapInTransaction($operation);
    }

    /**
     * @param list<Material> $materials
     */
    private function lockScope(
        EntityManagerInterface $entityManager,
        InventorySource $source,
        array $materials,
    ): void {
        $entityManager->refresh($source, LockMode::PESSIMISTIC_WRITE);
        usort(
            $materials,
            static fn (Material $left, Material $right): int =>
                $left->id() <=> $right->id(),
        );
        foreach ($materials as $material) {
            $entityManager->refresh($material, LockMode::PESSIMISTIC_WRITE);
        }
    }

    private function balanceForUpdate(
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        InventorySource $source,
        Material $material,
    ): MaterialInventoryBalance {
        $balance = $entityManager
            ->getRepository(MaterialInventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'material' => $material,
            ]);

        if (!$balance instanceof MaterialInventoryBalance) {
            $balance = new MaterialInventoryBalance(
                $tenant,
                $source,
                $material,
            );
            $entityManager->persist($balance);

            return $balance;
        }

        $entityManager->refresh($balance, LockMode::PESSIMISTIC_WRITE);

        return $balance;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private static function assertSameMovement(
        MaterialInventoryMovement $movement,
        InventorySource $source,
        Material $material,
        string $delta,
        array $context,
    ): void {
        if (
            $movement->source()->id() !== $source->id()
            || $movement->material()->id() !== $material->id()
            || $movement->delta() !== MaterialInventoryBalance::normalizeDelta(
                $delta,
            )
            || $movement->context() !== $context
        ) {
            throw new DomainException(
                'La clave de idempotencia ya fue usada por otro movimiento de material.',
            );
        }
    }

    private static function assertOwnedScope(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || $material->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'La fuente y el material deben pertenecer al tenant activo.',
            );
        }
    }

    private static function assertActiveScope(
        InventorySource $source,
        Material $material,
    ): void {
        self::assertSourceActive($source);
        self::assertMaterialActive($material);
    }

    private static function assertSourceActive(InventorySource $source): void
    {
        if (!$source->isActive()) {
            throw new DomainException(
                'No se puede modificar stock de materiales en una fuente inactiva.',
            );
        }
    }

    private static function assertMaterialActive(Material $material): void
    {
        if (!$material->isActive()) {
            throw new DomainException(
                'No se puede modificar stock de un material inactivo.',
            );
        }
    }

    private static function normalizeRootKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 80) {
            throw new DomainException(
                'La clave raíz de idempotencia admite máximo 80 caracteres.',
            );
        }

        return $key;
    }

    private static function derivedMaterialKey(
        string $root,
        Material $material,
    ): string {
        return MaterialInventoryMovement::normalizeIdempotencyKey(
            $root.':material:'.$material->id(),
        );
    }
}

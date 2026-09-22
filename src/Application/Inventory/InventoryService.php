<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class InventoryService
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
        ProductVariant $variant,
        int $delta,
        ?string $actorUserId,
        string $idempotencyKey,
        array $context = [],
    ): InventoryMovement {
        $key = self::normalizeIdempotencyKey($idempotencyKey);

        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use (
                $tenant,
                $source,
                $variant,
                $delta,
                $actorUserId,
                $key,
                $context,
            ): InventoryMovement {
                self::assertWritableScope($tenant, $source, $variant);
                $this->lockSourceAndVariant(
                    $entityManager,
                    $source,
                    $variant,
                );

                $existing = $entityManager
                    ->getRepository(InventoryMovement::class)
                    ->findOneBy([
                        'tenant' => $tenant,
                        'idempotencyKey' => $key,
                    ]);
                if ($existing instanceof InventoryMovement) {
                    self::assertSameAdjustment(
                        $existing,
                        $source,
                        $variant,
                        $delta,
                    );

                    return $existing;
                }

                $balance = $this->balanceForUpdate(
                    $entityManager,
                    $tenant,
                    $source,
                    $variant,
                );
                $balance->apply($delta);

                $movement = new InventoryMovement(
                    $tenant,
                    $source,
                    $variant,
                    $delta > 0
                        ? InventoryMovement::TYPE_ADJUSTMENT_IN
                        : InventoryMovement::TYPE_ADJUSTMENT_OUT,
                    $delta,
                    $balance->quantity(),
                    $actorUserId,
                    $key,
                    null,
                    $context,
                );
                $entityManager->persist($movement);

                return $movement;
            },
        );
    }

    public function transfer(
        Tenant $tenant,
        ProductVariant $variant,
        InventorySource $sourceFrom,
        InventorySource $sourceTo,
        int $quantity,
        ?string $actorUserId,
        string $idempotencyKey,
    ): InventoryTransfer {
        $key = self::normalizeIdempotencyKey($idempotencyKey);

        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use (
                $tenant,
                $variant,
                $sourceFrom,
                $sourceTo,
                $quantity,
                $actorUserId,
                $key,
            ): InventoryTransfer {
                self::assertWritableScope($tenant, $sourceFrom, $variant);
                self::assertWritableScope($tenant, $sourceTo, $variant);

                $this->lockSourcesAndVariant(
                    $entityManager,
                    [$sourceFrom, $sourceTo],
                    $variant,
                );

                $existing = $entityManager
                    ->getRepository(InventoryTransfer::class)
                    ->findOneBy([
                        'tenant' => $tenant,
                        'idempotencyKey' => $key,
                    ]);
                if ($existing instanceof InventoryTransfer) {
                    self::assertSameTransfer(
                        $existing,
                        $variant,
                        $sourceFrom,
                        $sourceTo,
                        $quantity,
                    );

                    return $existing;
                }

                $transfer = new InventoryTransfer(
                    $tenant,
                    $variant,
                    $sourceFrom,
                    $sourceTo,
                    $quantity,
                    $actorUserId,
                    $key,
                );

                $fromBalance = $this->balanceForUpdate(
                    $entityManager,
                    $tenant,
                    $sourceFrom,
                    $variant,
                );
                $toBalance = $this->balanceForUpdate(
                    $entityManager,
                    $tenant,
                    $sourceTo,
                    $variant,
                );

                $fromBalance->apply(-$quantity);
                $toBalance->apply($quantity);

                $entityManager->persist($transfer);

                $entityManager->persist(new InventoryMovement(
                    $tenant,
                    $sourceFrom,
                    $variant,
                    InventoryMovement::TYPE_TRANSFER_OUT,
                    -$quantity,
                    $fromBalance->quantity(),
                    $actorUserId,
                    null,
                    $transfer,
                    ['destination_source_id' => $sourceTo->id()],
                ));
                $entityManager->persist(new InventoryMovement(
                    $tenant,
                    $sourceTo,
                    $variant,
                    InventoryMovement::TYPE_TRANSFER_IN,
                    $quantity,
                    $toBalance->quantity(),
                    $actorUserId,
                    null,
                    $transfer,
                    ['origin_source_id' => $sourceFrom->id()],
                ));

                return $transfer;
            },
        );
    }

    private function balanceForUpdate(
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): InventoryBalance {
        $balance = $entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);

        if (!$balance instanceof InventoryBalance) {
            $balance = new InventoryBalance(
                $tenant,
                $source,
                $variant,
            );
            $entityManager->persist($balance);

            return $balance;
        }

        $entityManager->lock($balance, LockMode::PESSIMISTIC_WRITE);

        return $balance;
    }

    private function lockSourceAndVariant(
        EntityManagerInterface $entityManager,
        InventorySource $source,
        ProductVariant $variant,
    ): void {
        $entityManager->lock($source, LockMode::PESSIMISTIC_WRITE);
        $entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @param list<InventorySource> $sources
     */
    private function lockSourcesAndVariant(
        EntityManagerInterface $entityManager,
        array $sources,
        ProductVariant $variant,
    ): void {
        usort(
            $sources,
            static fn (InventorySource $left, InventorySource $right): int =>
                $left->id() <=> $right->id(),
        );

        foreach ($sources as $source) {
            $entityManager->lock($source, LockMode::PESSIMISTIC_WRITE);
        }
        $entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
    }

    private static function assertWritableScope(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || $variant->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'La fuente y la variante deben pertenecer al tenant activo.',
            );
        }

        if (!$source->isActive()) {
            throw new DomainException(
                'No se puede modificar inventario de una fuente inactiva.',
            );
        }

        if (!$variant->isActive() || !$variant->product()->isActive()) {
            throw new DomainException(
                'No se puede modificar inventario de una variante inactiva.',
            );
        }
    }

    private static function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 120) {
            throw new DomainException(
                'La clave de idempotencia es obligatoria y admite máximo 120 caracteres.',
            );
        }

        return $key;
    }

    private static function assertSameAdjustment(
        InventoryMovement $movement,
        InventorySource $source,
        ProductVariant $variant,
        int $delta,
    ): void {
        $expectedType = $delta > 0
            ? InventoryMovement::TYPE_ADJUSTMENT_IN
            : InventoryMovement::TYPE_ADJUSTMENT_OUT;

        if (
            $movement->transfer() instanceof InventoryTransfer
            || $movement->source()->id() !== $source->id()
            || $movement->variant()->id() !== $variant->id()
            || $movement->delta() !== $delta
            || $movement->type() !== $expectedType
        ) {
            throw new DomainException(
                'La clave de idempotencia ya fue usada por otro ajuste.',
            );
        }
    }

    private static function assertSameTransfer(
        InventoryTransfer $transfer,
        ProductVariant $variant,
        InventorySource $sourceFrom,
        InventorySource $sourceTo,
        int $quantity,
    ): void {
        if (
            $transfer->variant()->id() !== $variant->id()
            || $transfer->sourceFrom()->id() !== $sourceFrom->id()
            || $transfer->sourceTo()->id() !== $sourceTo->id()
            || $transfer->quantity() !== $quantity
        ) {
            throw new DomainException(
                'La clave de idempotencia ya fue usada por otra transferencia.',
            );
        }
    }
}

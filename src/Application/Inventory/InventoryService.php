<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryReservation;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
     * @param null|callable(InventoryMovement): void $onCreated
     */
    public function adjust(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
        int $delta,
        ?string $actorUserId,
        string $idempotencyKey,
        array $context = [],
        ?callable $onCreated = null,
    ): InventoryMovement {
        $key = self::normalizeIdempotencyKey($idempotencyKey);

        try {
            return $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use (
                $tenant,
                $source,
                $variant,
                $delta,
                $actorUserId,
                $key,
                $context,
                $onCreated,
            ): InventoryMovement {
                self::assertOwnedScope($tenant, $source, $variant);
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

                self::assertActiveScope($source, $variant);

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
                if ($onCreated !== null) {
                    $onCreated($movement);
                }

                return $movement;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new InventoryConflictException(
                'La operación de inventario entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    public function transfer(
        Tenant $tenant,
        ProductVariant $variant,
        InventorySource $sourceFrom,
        InventorySource $sourceTo,
        int $quantity,
        ?string $actorUserId,
        string $idempotencyKey,
        ?callable $onCreated = null,
    ): InventoryTransfer {
        $key = self::normalizeIdempotencyKey($idempotencyKey);

        try {
            return $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use (
                $tenant,
                $variant,
                $sourceFrom,
                $sourceTo,
                $quantity,
                $actorUserId,
                $key,
                $onCreated,
            ): InventoryTransfer {
                self::assertOwnedScope($tenant, $sourceFrom, $variant);
                self::assertOwnedScope($tenant, $sourceTo, $variant);

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

                self::assertActiveScope($sourceFrom, $variant);
                self::assertActiveScope($sourceTo, $variant);

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
                if ($onCreated !== null) {
                    $onCreated($transfer);
                }

                return $transfer;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new InventoryConflictException(
                'La operación de inventario entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    public function reserveOrderLine(
        OrderLine $orderLine,
        string $reserveKey,
    ): InventoryReservation {
        $order = $orderLine->order();
        $tenant = $order->tenant();
        $source = $order->inventorySource();
        $variant = $orderLine->variant();

        try {
            return $this->transactional(
                function (EntityManagerInterface $entityManager) use (
                    $tenant,
                    $source,
                    $variant,
                    $orderLine,
                    $reserveKey,
                ): InventoryReservation {
                    self::assertOwnedScope($tenant, $source, $variant);
                    $this->lockSourceAndVariant(
                        $entityManager,
                        $source,
                        $variant,
                    );

                    $existing = $entityManager
                        ->getRepository(InventoryReservation::class)
                        ->findOneBy([
                            'tenant' => $tenant,
                            'reserveKey' => trim($reserveKey),
                        ]);
                    if ($existing instanceof InventoryReservation) {
                        self::assertSameReservation(
                            $existing,
                            $orderLine,
                            $source,
                        );

                        return $existing;
                    }

                    self::assertActiveScope($source, $variant);

                    $balance = $this->balanceForUpdate(
                        $entityManager,
                        $tenant,
                        $source,
                        $variant,
                    );
                    $balance->reserve($orderLine->quantity());

                    $reservation = new InventoryReservation(
                        $orderLine,
                        $source,
                        $reserveKey,
                    );
                    $entityManager->persist($reservation);

                    return $reservation;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new InventoryConflictException(
                'La reserva de inventario entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    public function releaseReservation(
        InventoryReservation $reservation,
        string $releaseKey,
    ): InventoryReservation {
        try {
            return $this->transactional(
                function (EntityManagerInterface $entityManager) use (
                    $reservation,
                    $releaseKey,
                ): InventoryReservation {
                    $tenant = $reservation->tenant();
                    $source = $reservation->source();
                    $variant = $reservation->variant();

                    self::assertOwnedScope($tenant, $source, $variant);
                    $this->lockSourceAndVariant(
                        $entityManager,
                        $source,
                        $variant,
                    );
                    $balance = $this->reservedBalanceForUpdate(
                        $entityManager,
                        $tenant,
                        $source,
                        $variant,
                    );
                    $entityManager->refresh(
                        $reservation,
                        LockMode::PESSIMISTIC_WRITE,
                    );

                    if ($reservation->markReleased($releaseKey)) {
                        $balance->releaseReserved(
                            $reservation->quantity(),
                        );
                    }

                    return $reservation;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new InventoryConflictException(
                'La liberación de inventario entró en conflicto con otra solicitud concurrente.',
                0,
                $exception,
            );
        }
    }

    public function consumeReservation(
        InventoryReservation $reservation,
        string $consumeKey,
        ?string $actorUserId = null,
    ): InventoryReservation {
        try {
            return $this->transactional(
                function (EntityManagerInterface $entityManager) use (
                    $reservation,
                    $consumeKey,
                    $actorUserId,
                ): InventoryReservation {
                    $tenant = $reservation->tenant();
                    $source = $reservation->source();
                    $variant = $reservation->variant();

                    self::assertOwnedScope($tenant, $source, $variant);
                    $this->lockSourceAndVariant(
                        $entityManager,
                        $source,
                        $variant,
                    );
                    $balance = $this->reservedBalanceForUpdate(
                        $entityManager,
                        $tenant,
                        $source,
                        $variant,
                    );
                    $entityManager->refresh(
                        $reservation,
                        LockMode::PESSIMISTIC_WRITE,
                    );

                    if (!$reservation->markConsumed($consumeKey)) {
                        return $reservation;
                    }

                    $balance->consumeReserved($reservation->quantity());
                    $entityManager->persist(new InventoryMovement(
                        $tenant,
                        $source,
                        $variant,
                        InventoryMovement::TYPE_ORDER_CONSUMPTION,
                        -$reservation->quantity(),
                        $balance->quantity(),
                        $actorUserId,
                        trim($consumeKey),
                        null,
                        [
                            'order_id' => $reservation->order()->id(),
                            'order_line_id' => $reservation->orderLine()->id(),
                            'reservation_id' => $reservation->id(),
                        ],
                    ));

                    return $reservation;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new InventoryConflictException(
                'El consumo de inventario entró en conflicto con otra solicitud concurrente.',
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

    private function reservedBalanceForUpdate(
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
            throw new DomainException(
                'La reserva no tiene un saldo de inventario asociado.',
            );
        }

        $entityManager->refresh($balance, LockMode::PESSIMISTIC_WRITE);

        return $balance;
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

        $entityManager->refresh($balance, LockMode::PESSIMISTIC_WRITE);

        return $balance;
    }

    private function lockSourceAndVariant(
        EntityManagerInterface $entityManager,
        InventorySource $source,
        ProductVariant $variant,
    ): void {
        $entityManager->refresh($source, LockMode::PESSIMISTIC_WRITE);
        $entityManager->refresh(
            $variant->product(),
            LockMode::PESSIMISTIC_READ,
        );
        $entityManager->refresh($variant, LockMode::PESSIMISTIC_WRITE);
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
            $entityManager->refresh($source, LockMode::PESSIMISTIC_WRITE);
        }
        $entityManager->refresh(
            $variant->product(),
            LockMode::PESSIMISTIC_READ,
        );
        $entityManager->refresh($variant, LockMode::PESSIMISTIC_WRITE);
    }

    private static function assertOwnedScope(
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
    }

    private static function assertActiveScope(
        InventorySource $source,
        ProductVariant $variant,
    ): void {
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

    private static function assertSameReservation(
        InventoryReservation $reservation,
        OrderLine $orderLine,
        InventorySource $source,
    ): void {
        if (
            $reservation->orderLine()->id() !== $orderLine->id()
            || $reservation->order()->id() !== $orderLine->order()->id()
            || $reservation->source()->id() !== $source->id()
            || $reservation->variant()->id() !== $orderLine->variant()->id()
            || $reservation->quantity() !== $orderLine->quantity()
        ) {
            throw new DomainException(
                'La clave de idempotencia ya fue usada por otra reserva.',
            );
        }
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

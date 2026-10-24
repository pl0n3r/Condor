<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use DomainException;

/**
 * Branch-to-branch transfers reuse the existing inventory ledger. Unlike
 * backorders, physical stock transfers may never make origin stock negative.
 */
final readonly class StockTransferService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InventoryService $inventory,
    ) {
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
        if (
            $quantity <= 0 || $quantity > 2147483647
            || trim($idempotencyKey) === ''
            || mb_strlen(trim($idempotencyKey), 'UTF-8') > 120
        ) {
            throw new DomainException('La cantidad o clave de transferencia no es válida.');
        }

        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $manager) use (
                $tenant,
                $variant,
                $sourceFrom,
                $sourceTo,
                $quantity,
                $actorUserId,
                $idempotencyKey,
                $onCreated,
            ): InventoryTransfer {
                if (
                    $variant->tenant()->id() !== $tenant->id()
                    || $sourceFrom->tenant()->id() !== $tenant->id()
                    || $sourceTo->tenant()->id() !== $tenant->id()
                ) {
                    throw new DomainException('La transferencia debe permanecer en el tenant activo.');
                }

                // Lock both sources in canonical order, matching InventoryService,
                // so competing transfers cannot both spend the same stock.
                $sources = [$sourceFrom, $sourceTo];
                usort($sources, static fn (InventorySource $a, InventorySource $b): int => $a->id() <=> $b->id());
                foreach ($sources as $source) {
                    $manager->refresh($source, LockMode::PESSIMISTIC_WRITE);
                }
                $manager->refresh($variant->product(), LockMode::PESSIMISTIC_READ);
                $manager->refresh($variant, LockMode::PESSIMISTIC_WRITE);

                if (
                    $sourceFrom->type() !== InventorySource::TYPE_BRANCH
                    || $sourceTo->type() !== InventorySource::TYPE_BRANCH
                    || $sourceFrom->branch() === null
                    || $sourceTo->branch() === null
                    || $sourceFrom->id() === $sourceTo->id()
                    || $sourceFrom->legalEntity()->id() !== $sourceTo->legalEntity()->id()
                ) {
                    throw new DomainException('La transferencia requiere dos sedes diferentes de la misma entidad legal.');
                }
                if (!$sourceFrom->isActive() || !$sourceTo->isActive()) {
                    throw new DomainException('Las dos fuentes de inventario deben estar activas.');
                }

                // Replays must be recognized before checking current availability;
                // a successfully completed transfer never spends stock twice.
                $existing = $manager->getRepository(InventoryTransfer::class)->findOneBy([
                    'tenant' => $tenant,
                    'idempotencyKey' => trim($idempotencyKey),
                ]);
                if ($existing instanceof InventoryTransfer) {
                    if (
                        $existing->variant()->id() !== $variant->id()
                        || $existing->sourceFrom()->id() !== $sourceFrom->id()
                        || $existing->sourceTo()->id() !== $sourceTo->id()
                        || $existing->quantity() !== $quantity
                    ) {
                        throw new DomainException('La clave de idempotencia corresponde a otra transferencia.');
                    }

                    return $existing;
                }

                $balance = $manager->getRepository(InventoryBalance::class)->findOneBy([
                    'tenant' => $tenant,
                    'source' => $sourceFrom,
                    'variant' => $variant,
                ]);
                if ($balance instanceof InventoryBalance) {
                    $manager->refresh($balance, LockMode::PESSIMISTIC_WRITE);
                }
                if (
                    !$balance instanceof InventoryBalance
                    || $balance->quantity() - $balance->reservedQuantity() < $quantity
                ) {
                    throw new DomainException('Stock insuficiente para transferir sin saldo negativo ni consumir reservas.');
                }

                // The existing operation commits both movements, the transfer
                // record and audit callback atomically in this same transaction.
                return $this->inventory->transfer(
                    $tenant,
                    $variant,
                    $sourceFrom,
                    $sourceTo,
                    $quantity,
                    $actorUserId,
                    $idempotencyKey,
                    $onCreated,
                );
            },
        );
    }
}

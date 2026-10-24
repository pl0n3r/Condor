<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Read-only projection of a channel's one effective inventory source.
 * Never searches another branch or creates/mutates a stock balance.
 */
final readonly class ChannelInventorySource
{
    public function effectiveSource(Tenant $tenant, SalesChannel $channel): InventorySource
    {
        if ($channel->tenant()->id() !== $tenant->id() || !$channel->isActive()) {
            throw new DomainException('El canal de venta no pertenece al tenant activo o está deshabilitado.');
        }

        $source = $channel->inventorySource();
        if (
            $source->tenant()->id() !== $tenant->id()
            || $source->legalEntity()->id() !== $channel->legalEntity()->id()
            || !$source->isActive()
        ) {
            throw new DomainException('La fuente de inventario del canal no está disponible en su ámbito.');
        }

        return $source;
    }

    /**
     * @return array{source_id: string, status: 'in_stock'|'out_of_stock', sellable_quantity: int, backorder_allowed: bool, can_fulfill: bool}
     */
    public function availability(
        Tenant $tenant,
        SalesChannel $channel,
        ProductVariant $variant,
        ?InventoryBalance $balance,
        int $requestedQuantity = 1,
    ): array {
        if ($requestedQuantity < 1) {
            throw new DomainException('La cantidad solicitada debe ser mayor que cero.');
        }

        $source = $this->effectiveSource($tenant, $channel);
        if (
            $variant->tenant()->id() !== $tenant->id()
            || !$variant->isActive()
            || !$variant->product()->isActive()
        ) {
            throw new DomainException('La variante no está activa en el tenant del canal.');
        }
        if (
            $balance !== null
            && (
                $balance->tenant()->id() !== $tenant->id()
                || $balance->source()->id() !== $source->id()
                || $balance->variant()->id() !== $variant->id()
                || $balance->legalEntity()->id() !== $source->legalEntity()->id()
            )
        ) {
            throw new DomainException('El saldo no corresponde a la única fuente autorizada del canal.');
        }

        $sellable = max(0, $balance?->sellableQuantity() ?? 0);
        $backorder = $variant->product()->allowsBackorder();

        return [
            'source_id' => $source->id(),
            'status' => $sellable > 0 ? 'in_stock' : 'out_of_stock',
            'sellable_quantity' => $sellable,
            'backorder_allowed' => $backorder,
            'can_fulfill' => $sellable >= $requestedQuantity || $backorder,
        ];
    }
}

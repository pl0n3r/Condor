<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use DomainException;

/**
 * Read-only proposal: a donor must cover the *entire* destination shortage
 * while retaining its own 30-day demand and all reserved units.
 */
final readonly class TransferProposalSuggester
{
    public function __construct(private RestockSuggester $restock)
    {
    }

    /**
     * @return null|array{quantity:int,destination_available:int,origin_excess:int}
     */
    public function suggest(
        int $destinationConsumed30d,
        int $destinationOnHand,
        int $destinationReserved,
        int $originConsumed30d,
        int $originOnHand,
        int $originReserved,
    ): ?array {
        if (
            $originConsumed30d < 0 || $originConsumed30d > 2147483647
            || $originReserved < 0 || $originReserved > 2147483647
            || $originOnHand < -2147483647 || $originOnHand > 2147483647
        ) {
            throw new DomainException('Cantidades de origen fuera de rango.');
        }

        $need = $this->restock->suggest(
            $destinationConsumed30d,
            $destinationOnHand,
            $destinationReserved,
        );
        if ($need === null) {
            return null;
        }

        $free = max(0, $originOnHand - $originReserved);
        $excess = max(0, $free - $originConsumed30d);
        if ($excess < $need['suggested_quantity']) {
            return null;
        }

        return [
            'quantity' => $need['suggested_quantity'],
            'destination_available' => $need['available_quantity'],
            'origin_excess' => $excess,
        ];
    }
}

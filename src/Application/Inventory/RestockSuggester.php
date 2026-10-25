<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use DomainException;

/** Thirty-day consumption estimate; no persistence or external side effects. */
final readonly class RestockSuggester
{
    private const DAYS = 30;
    private const DB_MAX = 2147483647;

    /**
     * @return null|array{consumed_30d:int,average_daily:float,coverage_days:float,available_quantity:int,suggested_quantity:int}
     */
    public function suggest(int $consumed30d, int $onHand, int $reserved = 0): ?array
    {
        if ($consumed30d < 0 || $consumed30d > self::DB_MAX
            || $reserved < 0 || $reserved > self::DB_MAX
            || $onHand < -self::DB_MAX || $onHand > self::DB_MAX) {
            throw new DomainException('Cantidades de inventario fuera de rango.');
        }
        if ($consumed30d === 0) {
            return null; // No historical demand: do not invent a restock quantity.
        }

        $available = max(0, $onHand - $reserved);
        $suggested = max(0, $consumed30d - $available);
        if ($suggested === 0) {
            return null;
        }

        return [
            'consumed_30d' => $consumed30d,
            'average_daily' => round($consumed30d / self::DAYS, 4),
            'coverage_days' => round($available * self::DAYS / $consumed30d, 2),
            'available_quantity' => $available,
            'suggested_quantity' => $suggested,
        ];
    }
}

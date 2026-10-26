<?php

declare(strict_types=1);

namespace App\Tests\Application\Inventory;

use App\Application\Inventory\RestockSuggester;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RestockSuggesterTest extends TestCase
{
    public function testThirtyDayDemandAndReservations(): void
    {
        $value = (new RestockSuggester())->suggest(90, 15, 3);
        self::assertSame([
            'consumed_30d' => 90,
            'average_daily' => 3.0,
            'coverage_days' => 4.0,
            'available_quantity' => 12,
            'suggested_quantity' => 78,
        ], $value);
    }

    public function testNoConsumptionOrSufficientStockNeedsNoRestock(): void
    {
        $suggester = new RestockSuggester();
        self::assertNull($suggester->suggest(0, 0));
        self::assertNull($suggester->suggest(30, 35));
    }

    public function testBackorderedBalanceDoesNotReduceSuggestedQuantity(): void
    {
        $value = (new RestockSuggester())->suggest(30, -2, 0);
        self::assertSame(0, $value['available_quantity']);
        self::assertSame(30, $value['suggested_quantity']);
    }

    public function testInvalidStoredQuantityFailsClosed(): void
    {
        $this->expectException(DomainException::class);
        (new RestockSuggester())->suggest(-1, 4, 0);
    }
}

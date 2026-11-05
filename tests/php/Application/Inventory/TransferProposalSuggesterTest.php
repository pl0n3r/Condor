<?php

declare(strict_types=1);

namespace App\Tests\Application\Inventory;

use App\Application\Inventory\RestockSuggester;
use App\Application\Inventory\TransferProposalSuggester;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TransferProposalSuggesterTest extends TestCase
{
    public function testOnlyCompleteDeficitsWithRealDonorExcessAreProposed(): void
    {
        $service = new TransferProposalSuggester(new RestockSuggester());
        self::assertSame([
            'quantity' => 8,
            'destination_available' => 2,
            'origin_excess' => 24,
        ], $service->suggest(10, 2, 0, 4, 30, 2));
        self::assertNull($service->suggest(10, 2, 0, 4, 11, 0));
        // Reserved units are not donor surplus: a complete transfer must
        // preserve both reservations and the donor's own recent demand.
        self::assertNull($service->suggest(10, 2, 0, 4, 17, 6));
        self::assertSame([
            'quantity' => 8,
            'destination_available' => 2,
            'origin_excess' => 8,
        ], $service->suggest(10, 2, 0, 4, 18, 6));
        self::assertNull($service->suggest(10, 10, 0, 4, 30, 0));
        self::assertNull($service->suggest(0, 0, 0, 0, 30, 0));
        self::assertNull($service->suggest(10, 2, 0, 25, 30, 0));
    }

    public function testInvalidDonorQuantitiesFailClosed(): void
    {
        $this->expectException(DomainException::class);
        (new TransferProposalSuggester(new RestockSuggester()))
            ->suggest(10, 2, 0, -1, 30, 0);
    }
}


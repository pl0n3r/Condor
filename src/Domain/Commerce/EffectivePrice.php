<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

final readonly class EffectivePrice
{
    public function __construct(
        public int $baseAmountMinor,
        public int $effectiveAmountMinor,
        public string $currency,
        public string $priceListId,
        public ?string $ruleId,
    ) {
    }
}

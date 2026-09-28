<?php

declare(strict_types=1);

namespace App\Application\Market;

use App\Domain\Market\MarketScope;

final class CondorMarketDefaults
{
    /** @param array<string, mixed> $overrides */
    public static function initial(array $overrides = []): MarketScope
    {
        return MarketScope::fromArray(array_replace([
            'mode' => 'single_country',
            'primary_country' => 'CO',
            'target_countries' => ['CO'],
            'excluded_countries' => [],
            'launch_countries' => ['CO'],
            'expansion_candidates' => [],
            'default_currency' => 'COP',
            'default_locale' => 'es-CO',
        ], $overrides));
    }
}

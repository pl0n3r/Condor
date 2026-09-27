<?php
declare(strict_types=1);

namespace App\Application\Commercial;

final class PlanConfigurationRules
{
    /** @var array<string,string> */
    public const SCALE_ADDONS = [
        'users' => 'extra-user',
        'locations' => 'extra-location',
        'companies' => 'extra-company',
    ];

    public static function isSelectableAddOn(string $key): bool
    {
        return !in_array($key, self::SCALE_ADDONS, true);
    }
}

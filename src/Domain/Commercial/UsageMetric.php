<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

use DomainException;

enum UsageMetric: string
{
    case Companies = 'companies';
    case Users = 'users';
    case Locations = 'locations';
    case StorageMb = 'storage_mb';
    case Orders = 'orders';
    case ApiCalls = 'api_calls';

    public static function fromKey(string $key): self
    {
        $metric = self::tryFrom(strtolower(trim($key)));
        if ($metric === null) {
            throw new DomainException('Métrica de usage desconocida.');
        }

        return $metric;
    }

    public function aggregation(): string
    {
        return match ($this) {
            self::Companies,
            self::Users,
            self::Locations,
            self::StorageMb => 'max',
            self::Orders,
            self::ApiCalls => 'sum',
        };
    }
}

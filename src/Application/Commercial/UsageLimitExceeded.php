<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;

final class UsageLimitExceeded extends DomainException
{
    public function __construct(public readonly string $metric, public readonly int $limit)
    {
        parent::__construct(sprintf('Límite del plan alcanzado para %s (%d).', $metric, $limit));
    }
}

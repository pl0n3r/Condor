<?php

declare(strict_types=1);

namespace App\Domain\Commercial;

enum UsageAggregation: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
}

<?php

declare(strict_types=1);

namespace App\Application\Async;

use RuntimeException;

final class OutboxUnavailable extends RuntimeException
{
}

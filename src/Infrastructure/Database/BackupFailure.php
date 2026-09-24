<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use RuntimeException;

final class BackupFailure extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly int $exitCode,
        public readonly string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Identity;

use DateTimeImmutable;
use DateTimeZone;

final class PasswordResetSecurity
{
    private const TTL = '+60 minutes';

    /** @return array{0:string,1:string,2:DateTimeImmutable} */
    public function issue(): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $now = $this->now();

        return [
            $rawToken,
            hash('sha256', $rawToken),
            $now->modify(self::TTL),
        ];
    }

    public function hashRawToken(string $rawToken): ?string
    {
        $rawToken = strtolower(trim($rawToken));
        if (preg_match('/^[a-f0-9]{64}$/D', $rawToken) !== 1) {
            return null;
        }

        return hash('sha256', $rawToken);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

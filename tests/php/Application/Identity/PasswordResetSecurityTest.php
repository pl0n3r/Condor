<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\PasswordResetSecurity;
use PHPUnit\Framework\TestCase;

final class PasswordResetSecurityTest extends TestCase
{
    public function testItIssuesOpaqueOneHourTokenAndOnlyExposesHashForPersistence(): void
    {
        $security = new PasswordResetSecurity();
        [$raw, $hash, $expiresAt] = $security->issue();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $raw);
        self::assertSame(hash('sha256', $raw), $hash);
        self::assertSame($hash, $security->hashRawToken($raw));
        self::assertLessThanOrEqual(
            3600,
            $expiresAt->getTimestamp() - time(),
        );
        self::assertGreaterThanOrEqual(
            3590,
            $expiresAt->getTimestamp() - time(),
        );
        self::assertNull($security->hashRawToken('invalid'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    public function testTokenIsSingleUseAndCanBeReissuedBeforeConsumption(): void
    {
        $user = new User('admin@example.test', 'Admin');
        $now = new DateTimeImmutable('2026-09-25T04:00:00+00:00');
        $token = new PasswordResetToken(
            $user,
            str_repeat('a', 64),
            $now->modify('+60 minutes'),
        );

        self::assertTrue($token->isUsableAt($now));

        $token->reissue(
            str_repeat('b', 64),
            $now->modify('+30 minutes'),
            $now->modify('+1 minute'),
        );
        self::assertSame(str_repeat('b', 64), $token->tokenHash());

        $token->consume($now->modify('+2 minutes'));
        self::assertFalse($token->isUsableAt($now->modify('+3 minutes')));

        $this->expectException(DomainException::class);
        $token->consume($now->modify('+4 minutes'));
    }

    public function testRejectsInvalidHash(): void
    {
        $this->expectException(DomainException::class);

        new PasswordResetToken(
            new User('admin@example.test', 'Admin'),
            'raw-token',
            new DateTimeImmutable('+1 hour'),
        );
    }
}

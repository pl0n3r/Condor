<?php
declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Identity\AccountPasswordPolicy;
use App\Application\Identity\PasswordResetSecurity;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetPrimitivesTest extends TestCase
{
    public function testResetTokenIsHashedSingleUseRevocableAndReissuable(): void
    {
        $now = new DateTimeImmutable('2026-09-27T15:00:00+00:00');
        $token = new PasswordResetToken(
            new User('admin@example.test', 'Admin'),
            str_repeat('a', 64),
            $now->modify('+60 minutes'),
        );
        self::assertTrue($token->isUsableAt($now));
        $token->revoke($now->modify('+1 minute'));
        self::assertFalse($token->isUsableAt($now->modify('+1 minute')));
        $token->reissue(str_repeat('b', 64), $now->modify('+30 minutes'), $now->modify('+2 minutes'));
        self::assertSame(str_repeat('b', 64), $token->tokenHash());
        self::assertTrue($token->isUsableAt($now->modify('+2 minutes')));
        $token->consume($now->modify('+3 minutes'));
        self::assertFalse($token->isUsableAt($now->modify('+4 minutes')));
        $this->expectException(DomainException::class);
        $token->consume($now->modify('+3 minutes'));
    }

    public function testResetTokenRejectsRawMaterial(): void
    {
        $this->expectException(DomainException::class);
        new PasswordResetToken(
            new User('admin@example.test', 'Admin'),
            'raw-token',
            new DateTimeImmutable('+1 hour'),
        );
    }

    public function testResetTokenRejectsExpiryBoundaryAndExpiredConsume(): void
    {
        $now = new DateTimeImmutable('2026-09-27T15:00:00+00:00');
        $token = new PasswordResetToken(
            new User('expired@example.test', 'Expired'),
            str_repeat('c', 64),
            $now,
        );
        self::assertFalse($token->isUsableAt($now));

        $this->expectException(DomainException::class);
        $token->consume($now->modify('+1 second'));
    }

    public function testSecurityIssuesOpaqueOneHourTokenAndHashesOnlyValidRawTokens(): void
    {
        $security = new PasswordResetSecurity();
        [$raw, $hash, $expiresAt] = $security->issue();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $raw);
        self::assertSame(hash('sha256', $raw), $hash);
        self::assertSame($hash, $security->hashRawToken($raw));
        self::assertGreaterThanOrEqual(3590, $expiresAt->getTimestamp() - time());
        self::assertLessThanOrEqual(3600, $expiresAt->getTimestamp() - time());
        self::assertNull($security->hashRawToken('invalid'));
    }

    public function testPasswordPolicyRejectsCommonIdentityReuseAndCurrentPassword(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturnCallback(
            static fn (User $user, string $candidate): bool => $candidate === 'Current-secure-password-123!',
        );
        $policy = new AccountPasswordPolicy($hasher);
        $user = new User('marcela@example.test', 'Marcela');
        $user->setPasswordHash('existing-hash');

        foreach ([
            'password1234',
            'password123456',
            '            ',
            'áááááá',
            'Marcela-super-segura-2026',
            'Current-secure-password-123!',
        ] as $candidate) {
            try {
                $policy->assertAcceptable($user, $candidate);
                self::fail('La política debe rechazar la contraseña.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
        $policy->assertAcceptable($user, 'Otra-frase-segura-456!');
        self::assertTrue(true);
    }
}

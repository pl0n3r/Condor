<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\AccountPasswordPolicy;
use App\Domain\Identity\Entity\User;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountPasswordPolicyTest extends TestCase
{
    public function testRejectsCommonPasswordAndPasswordContainingEmailIdentity(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);
        $policy = new AccountPasswordPolicy($hasher);
        $user = new User('marcela@example.test', 'Marcela');

        foreach (['password1234', 'Marcela-super-segura-2026'] as $candidate) {
            try {
                $policy->assertAcceptable($user, $candidate);
                self::fail('La política debe rechazar la contraseña.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsCurrentPasswordReuse(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(true);
        $policy = new AccountPasswordPolicy($hasher);
        $user = new User('admin@example.test', 'Admin');
        $user->setPasswordHash('existing-hash');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('distinta de la actual');

        $policy->assertAcceptable($user, 'Nueva-segura-12345');
    }
}

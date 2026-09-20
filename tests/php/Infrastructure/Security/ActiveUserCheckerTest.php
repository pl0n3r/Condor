<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\Identity\Entity\User;
use App\Infrastructure\Security\ActiveUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;

final class ActiveUserCheckerTest extends TestCase
{
    public function testItAllowsActiveUsers(): void
    {
        $checker = new ActiveUserChecker();
        $user = new User('active@example.test', 'Activa');

        $checker->checkPreAuth($user);
        $checker->checkPostAuth($user);

        self::assertTrue($user->isActive());
    }

    public function testItRejectsInactiveUsers(): void
    {
        $checker = new ActiveUserChecker();
        $user = new User('inactive@example.test', 'Inactiva');
        $user->deactivate();

        $this->expectException(DisabledException::class);
        $checker->checkPreAuth($user);
    }
}

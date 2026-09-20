<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Identity\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->assertActive($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->assertActive($user);
    }

    private function assertActive(UserInterface $user): void
    {
        if (!$user instanceof User || $user->isActive()) {
            return;
        }

        throw new DisabledException('La cuenta está desactivada.');
    }
}

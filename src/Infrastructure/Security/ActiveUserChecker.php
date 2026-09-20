<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Identity\Entity\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->assertActive($user);
    }

    public function checkPostAuth(UserInterface $user): void
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

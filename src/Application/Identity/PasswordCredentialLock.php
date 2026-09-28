<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PasswordCredentialLock
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function user(User $user): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM condor_user WHERE id = :id FOR UPDATE',
            ['id' => $user->id()],
        )->fetchOne();
        $this->entityManager->refresh($user);
    }

    public function reset(PasswordResetToken $reset): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM condor_password_reset WHERE id = :id FOR UPDATE',
            ['id' => $reset->id()],
        )->fetchOne();
        $this->entityManager->refresh($reset);
    }
}

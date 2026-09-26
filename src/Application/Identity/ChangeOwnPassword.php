<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class ChangeOwnPassword
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountPasswordPolicy $passwordPolicy,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordResetSecurity $security,
        private AccountPasswordNotifier $notifier,
    ) {
    }

    public function change(User $user, string $currentPassword, string $newPassword): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($user, $currentPassword, $newPassword): void {
                $this->lockUser($user);

                if (
                    !$user->isActive()
                    || !$this->passwordHasher->isPasswordValid(
                        $user,
                        $currentPassword,
                    )
                ) {
                    throw new DomainException(
                        'La contraseña actual no es válida.',
                    );
                }

                $this->passwordPolicy->assertAcceptable(
                    $user,
                    $newPassword,
                );

                $user->setPasswordHash(
                    $this->passwordHasher->hashPassword(
                        $user,
                        $newPassword,
                    ),
                );

            $reset = $this->entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);
                if ($reset instanceof PasswordResetToken) {
                    $this->lockReset($reset);
                    if (
                        $reset->consumedAt() === null
                        && $reset->revokedAt() === null
                    ) {
                        $reset->revoke($this->security->now());
                    }
                }

                $this->entityManager->persist(new PlatformAuditEvent(
                    $user->id(),
                    null,
                    'account.password_changed',
                    User::class,
                    $user->id(),
                ));
                $this->entityManager->flush();
            },
        );

        $this->notifier->passwordChanged(
            $user,
            'authenticated_change',
        );
    }

    private function lockUser(User $user): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM condor_user WHERE id = :id FOR UPDATE',
            ['id' => $user->id()],
        )->fetchOne();
        $this->entityManager->refresh($user);
    }

    private function lockReset(PasswordResetToken $reset): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM condor_password_reset WHERE id = :id FOR UPDATE',
            ['id' => $reset->id()],
        )->fetchOne();
        $this->entityManager->refresh($reset);
    }
}

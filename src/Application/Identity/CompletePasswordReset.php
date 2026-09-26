<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CompletePasswordReset
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PasswordResetSecurity $security,
        private AccountPasswordPolicy $passwordPolicy,
        private UserPasswordHasherInterface $passwordHasher,
        private AccountPasswordNotifier $notifier,
    ) {
    }

    public function complete(string $rawToken, string $plainPassword): User
    {
        $hash = $this->security->hashRawToken($rawToken);
        if ($hash === null) {
            throw new DomainException('El enlace de recuperación no es válido o expiró.');
        }

        // Resolver la identidad antes de iniciar la transacción evita fijar un
        // snapshot REPEATABLE READ anterior a los SELECT ... FOR UPDATE.
        $reset = $this->entityManager
            ->getRepository(PasswordResetToken::class)
            ->findOneBy(['tokenHash' => $hash]);
        if (!$reset instanceof PasswordResetToken) {
            throw new DomainException('El enlace de recuperación no es válido o expiró.');
        }

        $user = $reset->user();
        $user->id();

        $user = $this->entityManager->wrapInTransaction(function () use ($user, $reset, $hash, $plainPassword): User {
            $this->lockUser($user);
            $this->lockReset($reset);

            $now = $this->security->now();
            if (
                $reset->tokenHash() !== $hash
                || !$reset->isUsableAt($now)
            ) {
                throw new DomainException(
                    'El enlace de recuperación no es válido o expiró.',
                );
            }

            if (!$user->isActive()) {
                throw new DomainException('La cuenta no está disponible.');
            }

            $this->passwordPolicy->assertAcceptable($user, $plainPassword);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));
            $reset->consume($now);

            $this->entityManager->persist(new PlatformAuditEvent(
                $user->id(),
                null,
                'account.password_reset_completed',
                User::class,
                $user->id(),
                ['reset_id' => $reset->id()],
            ));
            $this->entityManager->flush();

            return $user;
        });

        $this->notifier->passwordChanged($user, 'recovery');

        return $user;
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

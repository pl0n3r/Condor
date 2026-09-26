<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RequestPasswordReset
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PasswordResetSecurity $security,
        private AccountPasswordNotifier $notifier,
    ) {
    }

    public function request(string $email): void
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::dummyWork();

            return;
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User || !$user->isActive()) {
            self::dummyWork();

            return;
        }

        $rawToken = $this->entityManager->wrapInTransaction(function () use ($user): ?string {
            $this->lockUser($user);
            if (!$user->isActive()) {
                return null;
            }

            [$rawToken, $tokenHash, $expiresAt] = $this->security->issue();
            $now = $this->security->now();

            $reset = $this->entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);

            if ($reset instanceof PasswordResetToken) {
                $this->lockReset($reset);
                $reset->reissue($tokenHash, $expiresAt, $now);
            } else {
                $this->entityManager->persist(new PasswordResetToken(
                    $user,
                    $tokenHash,
                    $expiresAt,
                ));
            }

            $this->entityManager->flush();

            return $rawToken;
        });

        if ($rawToken === null) {
            self::dummyWork();

            return;
        }

        $this->notifier->resetRequested($user, $rawToken);
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

    private static function dummyWork(): void
    {
        hash('sha256', random_bytes(32));
    }
}

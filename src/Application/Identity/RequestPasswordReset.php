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
        private PasswordResetUrlFactory $resetUrlFactory,
        private AccountPasswordNotifier $notifier,
        private PasswordCredentialLock $lock,
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
            $this->lock->user($user);
            if (!$user->isActive()) {
                return null;
            }

            [$rawToken, $tokenHash, $expiresAt] = $this->security->issue();
            $now = $this->security->now();

            $reset = $this->entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);

            if ($reset instanceof PasswordResetToken) {
                $this->lock->reset($reset);
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

        if (!is_string($rawToken)) {
            self::dummyWork();

            return;
        }

        $this->notifier->resetRequested(
            $user,
            $this->resetUrlFactory->resetUrl($rawToken),
        );
    }

    private static function dummyWork(): void
    {
        hash('sha256', random_bytes(32));
    }
}

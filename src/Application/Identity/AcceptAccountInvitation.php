<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AcceptAccountInvitation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function preview(string $rawToken): ?AccountInvitation
    {
        $hash = self::hashToken($rawToken);
        if ($hash === null) {
            return null;
        }

        $invitation = $this->entityManager
            ->getRepository(AccountInvitation::class)
            ->findOneBy(['tokenHash' => $hash]);

        if (!$invitation instanceof AccountInvitation) {
            return null;
        }

        return $invitation->isUsableAt(self::now())
            ? $invitation
            : null;
    }

    public function accept(
        string $rawToken,
        string $plainPassword,
    ): User {
        if (
            strlen($plainPassword) < 12
            || strlen($plainPassword) > 4096
        ) {
            throw new DomainException(
                'La contraseña debe tener entre 12 y 4096 caracteres.',
            );
        }

        $hash = self::hashToken($rawToken);
        if ($hash === null) {
            throw new DomainException(
                'La invitación no es válida o ya expiró.',
            );
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($hash, $plainPassword): User {
                $invitation = $this->entityManager
                    ->getRepository(AccountInvitation::class)
                    ->findOneBy(['tokenHash' => $hash]);

                if (!$invitation instanceof AccountInvitation) {
                    throw new DomainException(
                        'La invitación no es válida o ya expiró.',
                    );
                }

                $this->entityManager->getConnection()->executeQuery(
                    'SELECT id FROM condor_account_invitation '
                    .'WHERE id = :id FOR UPDATE',
                    ['id' => $invitation->id()],
                )->fetchOne();

                $now = self::now();
                if (!$invitation->isUsableAt($now)) {
                    throw new DomainException(
                        'La invitación no es válida o ya expiró.',
                    );
                }

                $user = $invitation->user();
                if ($user->isActive()) {
                    throw new DomainException(
                        'La cuenta ya está activa.',
                    );
                }

                $user->setPasswordHash(
                    $this->passwordHasher->hashPassword(
                        $user,
                        $plainPassword,
                    ),
                );
                $user->activate();
                $invitation->consume($now);
                $this->entityManager->persist(new PlatformAuditEvent(
                    $user->id(),
                    $invitation->tenant()?->id(),
                    'account.invitation_accepted',
                    User::class,
                    $user->id(),
                    ['invitation_kind' => $invitation->kind()],
                ));
                $this->entityManager->flush();

                return $user;
            },
        );
    }

    private static function hashToken(string $rawToken): ?string
    {
        $rawToken = strtolower(trim($rawToken));
        if (preg_match('/^[a-f0-9]{64}$/D', $rawToken) !== 1) {
            return null;
        }

        return hash('sha256', $rawToken);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

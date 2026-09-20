<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

final readonly class ProvisionPlatformOwner
{
    private const SINGLETON_KEY = 1;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function execute(
        string $email,
        string $displayName,
        string $plainPassword,
    ): User {
        $email = strtolower(trim($email));
        $displayName = trim($displayName);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('El correo del propietario no es válido.');
        }

        if ($displayName === '') {
            throw new DomainException('El nombre del propietario es obligatorio.');
        }

        if (strlen($plainPassword) < 12) {
            throw new DomainException(
                'La contraseña del propietario debe tener al menos 12 caracteres.',
            );
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $owner = $this->provisionUnderLock(
                $connection,
                $email,
                $displayName,
                $plainPassword,
            );
            $connection->commit();

            return $owner;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function provisionUnderLock(
        Connection $connection,
        string $email,
        string $displayName,
        string $plainPassword,
    ): User {
        $slot = $connection->fetchAssociative(
            'SELECT user_id FROM condor_platform_owner '
            .'WHERE singleton_key = :singleton_key FOR UPDATE',
            ['singleton_key' => self::SINGLETON_KEY],
        );

        if ($slot === false) {
            throw new RuntimeException(
                'Falta el registro singleton del propietario de plataforma.',
            );
        }

        $repository = $this->entityManager->getRepository(User::class);
        $ownerUserId = $slot['user_id'] ?? null;

        if (is_string($ownerUserId) && $ownerUserId !== '') {
            $owner = $repository->find($ownerUserId);
            if (!$owner instanceof User) {
                throw new RuntimeException(
                    'El registro propietario apunta a un usuario inexistente.',
                );
            }

            if ($owner->email() !== $email) {
                throw new DomainException(
                    'Condor ya tiene un propietario de plataforma distinto.',
                );
            }

            return $this->promoteAndRotate($owner, $plainPassword);
        }

        $existing = $repository->findOneBy(['email' => $email]);
        foreach ($repository->findAll() as $candidate) {
            if (!$candidate instanceof User || $candidate->email() === $email) {
                continue;
            }

            if (
                $candidate->hasRole(User::ROLE_PLATFORM_OWNER)
                || $candidate->hasRole(User::ROLE_LEGACY_SUPER_ADMIN)
            ) {
                throw new DomainException(
                    'Existe otra cuenta con privilegios globales; migra esa cuenta antes de definir al propietario.',
                );
            }
        }

        $owner = $existing instanceof User
            ? $existing
            : new User($email, $displayName);

        if (!$existing instanceof User) {
            $this->entityManager->persist($owner);
        }

        $this->promoteAndRotate($owner, $plainPassword);
        $this->entityManager->flush();

        $connection->executeStatement(
            'UPDATE condor_platform_owner SET user_id = :user_id '
            .'WHERE singleton_key = :singleton_key',
            [
                'user_id' => $owner->id(),
                'singleton_key' => self::SINGLETON_KEY,
            ],
        );

        return $owner;
    }

    private function promoteAndRotate(User $owner, string $plainPassword): User
    {
        $owner->grantRole(User::ROLE_PLATFORM_OWNER);
        $owner->revokeRole(User::ROLE_LEGACY_SUPER_ADMIN);
        $owner->setPasswordHash(
            $this->passwordHasher->hashPassword($owner, $plainPassword),
        );
        $this->entityManager->flush();

        return $owner;
    }
}

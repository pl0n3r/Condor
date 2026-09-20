<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class ProvisionSuperAdmin
{
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
            throw new DomainException('El correo del Super Admin no es válido.');
        }

        if ($displayName === '') {
            throw new DomainException('El nombre del Super Admin es obligatorio.');
        }

        if (strlen($plainPassword) < 12) {
            throw new DomainException(
                'La contraseña del Super Admin debe tener al menos 12 caracteres.',
            );
        }

        $repository = $this->entityManager->getRepository(User::class);
        $existing = $repository->findOneBy(['email' => $email]);

        if ($existing instanceof User) {
            $existing->grantRole(User::ROLE_SUPER_ADMIN);
            $existing->setPasswordHash(
                $this->passwordHasher->hashPassword($existing, $plainPassword),
            );
            $this->entityManager->flush();

            return $existing;
        }

        $user = new User($email, $displayName, [User::ROLE_SUPER_ADMIN]);
        $user->setPasswordHash(
            $this->passwordHasher->hashPassword($user, $plainPassword),
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

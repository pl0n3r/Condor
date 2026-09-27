<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AccountPasswordPolicy
{
    private const COMMON = [
        '123456789012',
        '123456789123',
        'password1234',
        'password12345',
        'qwerty123456',
        'administrator',
        'administrador',
        'contraseña123',
        'contrasena123',
    ];

    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function assertAcceptable(User $user, string $plainPassword): void
    {
        $meaningful = trim($plainPassword);
        if (
            $meaningful === ''
            || mb_strlen($meaningful, 'UTF-8') < 12
            || strlen($plainPassword) > 4096
        ) {
            throw new DomainException(
                'La contraseña debe tener al menos 12 caracteres significativos y máximo 4096 bytes.',
            );
        }

        $normalized = mb_strtolower($meaningful, 'UTF-8');
        $looksCommon = preg_match(
            '/^(?:password|qwerty|administrator|administrador|contraseña|contrasena)(?:[0-9!@#$%^&*._-]+)?$/u',
            $normalized,
        ) === 1;
        if (in_array($normalized, self::COMMON, true) || $looksCommon) {
            throw new DomainException('Elige una contraseña menos común.');
        }

        $localPart = strstr($user->email(), '@', true);
        if (
            is_string($localPart)
            && mb_strlen($localPart, 'UTF-8') >= 4
            && str_contains(
                $normalized,
                mb_strtolower($localPart, 'UTF-8'),
            )
        ) {
            throw new DomainException('La contraseña no debe contener tu correo.');
        }

        if ($user->getPassword() !== '' && $this->passwordHasher->isPasswordValid($user, $plainPassword)) {
            throw new DomainException('La nueva contraseña debe ser distinta de la actual.');
        }
    }
}

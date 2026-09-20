<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\ProvisionSuperAdmin;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProvisionSuperAdminTest extends KernelTestCase
{
    public function testItCreatesAndReusesOneGlobalSuperAdmin(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(ProvisionSuperAdmin::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ProvisionSuperAdmin::class, $provisioner);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $email = 'super-'.$suffix.'@example.test';

        $first = $provisioner->execute(
            $email,
            'Super Admin',
            'super-admin-password-123',
        );
        $second = $provisioner->execute(
            $email,
            'Nombre ignorado al reutilizar',
            'super-admin-password-123',
        );

        self::assertSame($first->id(), $second->id());
        self::assertContains(User::ROLE_SUPER_ADMIN, $second->getRoles());
        self::assertSame(
            1,
            $entityManager->getRepository(User::class)->count(['email' => $email]),
        );
    }

    public function testItPromotesExistingUserAndReplacesPreviousPassword(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(ProvisionSuperAdmin::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ProvisionSuperAdmin::class, $provisioner);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $email = 'promote-'.$suffix.'@example.test';
        $previousPassword = 'previous-password-123';
        $provisioningPassword = 'new-super-admin-password-456';

        $user = new User($email, 'Usuario existente');
        $user->setPasswordHash(
            $passwordHasher->hashPassword($user, $previousPassword),
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $promoted = $provisioner->execute(
            $email,
            'Usuario existente',
            $provisioningPassword,
        );

        self::assertSame($user->id(), $promoted->id());
        self::assertContains(User::ROLE_SUPER_ADMIN, $promoted->getRoles());
        self::assertTrue(
            $passwordHasher->isPasswordValid($promoted, $provisioningPassword),
        );
        self::assertFalse(
            $passwordHasher->isPasswordValid($promoted, $previousPassword),
        );
        self::assertSame(
            1,
            $entityManager->getRepository(User::class)->count(['email' => $email]),
        );
    }
}

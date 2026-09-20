<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\ProvisionPlatformOwner;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProvisionPlatformOwnerTest extends KernelTestCase
{
    public function testItMigratesLegacyOwnerAndRotatesPassword(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(ProvisionPlatformOwner::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ProvisionPlatformOwner::class, $provisioner);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $email = 'owner-'.$suffix.'@example.test';
        $previousPassword = 'previous-owner-password-123';
        $ownerPassword = 'platform-owner-password-456';

        $user = new User(
            $email,
            'Propietario existente',
            [User::ROLE_LEGACY_SUPER_ADMIN],
        );
        $user->setPasswordHash(
            $passwordHasher->hashPassword($user, $previousPassword),
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $owner = $provisioner->execute(
            $email,
            'Propietario existente',
            $ownerPassword,
        );

        self::assertSame($user->id(), $owner->id());
        self::assertTrue($owner->hasRole(User::ROLE_PLATFORM_OWNER));
        self::assertFalse($owner->hasRole(User::ROLE_LEGACY_SUPER_ADMIN));
        self::assertTrue($passwordHasher->isPasswordValid($owner, $ownerPassword));
        self::assertFalse($passwordHasher->isPasswordValid($owner, $previousPassword));
        self::assertSame($owner->id(), $this->ownerSlotUserId($entityManager));

        $entityManager->remove($owner);
        $entityManager->flush();
        self::assertNull($this->ownerSlotUserId($entityManager));
    }

    public function testItIsIdempotentForTheSameOwner(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(ProvisionPlatformOwner::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ProvisionPlatformOwner::class, $provisioner);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $email = 'owner-'.$suffix.'@example.test';

        $first = $provisioner->execute(
            $email,
            'Propietario',
            'platform-owner-password-123',
        );
        $second = $provisioner->execute(
            $email,
            'Nombre ignorado al reutilizar',
            'platform-owner-password-456',
        );

        self::assertSame($first->id(), $second->id());
        self::assertTrue($second->hasRole(User::ROLE_PLATFORM_OWNER));
        self::assertSame(
            1,
            $entityManager->getRepository(User::class)->count(['email' => $email]),
        );
        self::assertSame($second->id(), $this->ownerSlotUserId($entityManager));

        $entityManager->remove($second);
        $entityManager->flush();
    }

    public function testItRejectsASecondPlatformOwner(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(ProvisionPlatformOwner::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ProvisionPlatformOwner::class, $provisioner);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $first = $provisioner->execute(
            'owner-a-'.$suffix.'@example.test',
            'Propietario',
            'platform-owner-password-123',
        );

        try {
            $provisioner->execute(
                'owner-b-'.$suffix.'@example.test',
                'Segundo propietario',
                'platform-owner-password-456',
            );
            self::fail('Debe rechazar un segundo propietario.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Condor ya tiene un propietario de plataforma distinto.',
                $exception->getMessage(),
            );
            self::assertSame($first->id(), $this->ownerSlotUserId($entityManager));
        } finally {
            $entityManager->remove($first);
            $entityManager->flush();
        }
    }

    public function testDatabaseKeepsExactlyOneSingletonSlot(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        self::assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM condor_platform_owner'),
        );
        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT singleton_key FROM condor_platform_owner',
            ),
        );
    }

    private function ownerSlotUserId(EntityManagerInterface $entityManager): ?string
    {
        $value = $entityManager->getConnection()->fetchOne(
            'SELECT user_id FROM condor_platform_owner WHERE singleton_key = 1',
        );

        return is_string($value) && $value !== '' ? $value : null;
    }
}

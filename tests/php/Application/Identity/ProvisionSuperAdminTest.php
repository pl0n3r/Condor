<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\ProvisionSuperAdmin;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
}

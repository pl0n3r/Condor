<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\PlatformStaffAuthorization;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\PlatformStaffGrant;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlatformStaffAuthorizationTest extends KernelTestCase
{
    public function testStaffUsesExplicitTenantAndGlobalCrudGrantsWithoutMembership(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $authorization = new PlatformStaffAuthorization($entityManager);

        $suffix = bin2hex(random_bytes(4));
        $first = new Tenant('Empresa A '.$suffix, 'empresa-a-'.$suffix);
        $second = new Tenant('Empresa B '.$suffix, 'empresa-b-'.$suffix);
        $staff = new User(
            'staff-'.$suffix.'@example.test',
            'Staff Condor',
            [User::ROLE_PLATFORM_STAFF],
        );

        foreach ([
            $first,
            $second,
            $staff,
            new PlatformStaffGrant(
                $staff,
                $first,
                'catalog',
                ['view', 'update'],
            ),
            new PlatformStaffGrant(
                $staff,
                null,
                'orders',
                ['view'],
            ),
        ] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        self::assertTrue(
            $authorization->can($staff, $first, 'catalog.view'),
        );
        self::assertTrue(
            $authorization->can($staff, $first, 'catalog.update'),
        );
        self::assertFalse(
            $authorization->can($staff, $first, 'catalog.delete'),
        );
        self::assertFalse(
            $authorization->can($staff, $second, 'catalog.view'),
        );
        self::assertTrue(
            $authorization->can($staff, $first, 'orders.view'),
        );
        self::assertTrue(
            $authorization->can($staff, $second, 'orders.view'),
        );

        self::assertSame(
            0,
            $entityManager->getRepository(Membership::class)
                ->count(['user' => $staff]),
        );

        self::assertSame(
            [$first->id(), $second->id()],
            array_map(
                static fn (Tenant $tenant): string => $tenant->id(),
                $authorization->accessibleTenants($staff),
            ),
        );

        $staff->deactivate();
        self::assertFalse(
            $authorization->can($staff, $first, 'orders.view'),
        );
        self::assertSame(
            [],
            $authorization->accessibleTenants($staff),
        );
    }

    public function testOwnerGetsCatalogPermissionsButNormalUserGetsNone(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $authorization = new PlatformStaffAuthorization($entityManager);

        $tenant = new Tenant(
            'Empresa Owner '.bin2hex(random_bytes(4)),
            'owner-'.bin2hex(random_bytes(6)),
        );
        $owner = new User(
            'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Owner',
            [User::ROLE_PLATFORM_OWNER],
        );
        $normal = new User(
            'normal-'.bin2hex(random_bytes(4)).'@example.test',
            'Normal',
        );

        self::assertTrue(
            $authorization->can($owner, $tenant, 'settings.delete'),
        );
        self::assertFalse(
            $authorization->can($normal, $tenant, 'settings.view'),
        );
        self::assertSame([], $authorization->permissions($normal, $tenant));
    }
}

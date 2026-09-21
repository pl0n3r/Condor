<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\BranchAuthorization;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BranchAuthorizationTest extends KernelTestCase
{
    public function testOwnerKeepsAllPermissionsWithoutBackfill(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $authorization = $container->get(BranchAuthorization::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(BranchAuthorization::class, $authorization);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Empresa', 'empresa-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal', null, true);
        $user = new User('owner-'.$suffix.'@example.test', 'Propietario');
        $membership = new Membership($tenant, $user, Membership::ROLE_OWNER);

        foreach ([$tenant, $branch, $user, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        self::assertSame(
            PermissionCatalog::all(),
            $authorization->permissions($user, $tenant, $branch),
        );
    }

    public function testPermissionsAccumulateAcrossRolesForOneBranch(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $authorization = $container->get(BranchAuthorization::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(BranchAuthorization::class, $authorization);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Empresa', 'empresa-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal', null, true);
        $user = new User('admin-'.$suffix.'@example.test', 'Administrador');
        $membership = new Membership($tenant, $user, 'ADMIN');
        $viewer = new Role($tenant, 'Consulta', ['catalog.view']);
        $editor = new Role($tenant, 'Edición', ['catalog.update']);

        foreach ([$tenant, $branch, $user, $membership, $viewer, $editor] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->persist(new BranchRoleAssignment($membership, $branch, $viewer));
        $entityManager->persist(new BranchRoleAssignment($membership, $branch, $editor));
        $entityManager->flush();

        self::assertSame(
            ['catalog.update', 'catalog.view'],
            $authorization->permissions($user, $tenant, $branch),
        );
    }

    public function testCrossTenantAssignmentIsRejectedBeforePersistence(): void
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $first = new Tenant('Uno', 'uno-'.$suffix);
        $second = new Tenant('Dos', 'dos-'.$suffix);
        $user = new User('user-'.$suffix.'@example.test', 'Usuario');
        $membership = new Membership($first, $user, 'ADMIN');
        $branch = new Branch($second, 'Otra', 'otra', null, true);
        $role = new Role($first, 'Consulta', ['catalog.view']);

        $this->expectException(DomainException::class);
        new BranchRoleAssignment($membership, $branch, $role);
    }

    public function testUnknownPermissionIsRejected(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.strtolower(bin2hex(random_bytes(4))));

        $this->expectException(DomainException::class);
        new Role($tenant, 'Inseguro', ['root.everything']);
    }
}

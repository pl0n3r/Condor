<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\BranchAuthorization;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** GEST-M14-S01/S02: require() must never grant a foreign tenant's branch. */
final class GestM14AuthorizationTest extends KernelTestCase
{
    public function testOwnerAndAdminCannotRequirePermissionOnForeignBranch(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $auth = static::getContainer()->get(BranchAuthorization::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(BranchAuthorization::class, $auth);

        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Autorización A', 'auth-a-'.$suffix);
        $foreignTenant = new Tenant('Autorización B', 'auth-b-'.$suffix);
        $branch = new Branch($tenant, 'Propia', 'own', null, true);
        $foreign = new Branch($foreignTenant, 'Ajena', 'foreign', null, true);
        $owner = new User('auth-owner-'.$suffix.'@example.test', 'Owner');
        $admin = new User('auth-admin-'.$suffix.'@example.test', 'Admin');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $adminMembership = new Membership($tenant, $admin, 'ADMIN');
        $viewRole = new Role($tenant, 'Ver roles', ['roles.view']);

        foreach ([
            $tenant, $foreignTenant, $branch, $foreign, $owner, $admin,
            $ownerMembership, $adminMembership, $viewRole,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new BranchRoleAssignment($adminMembership, $branch, $viewRole));
        $em->flush();

        // Positive control: the patch cannot revoke legitimate same-tenant access.
        self::assertSame($ownerMembership, $auth->require($owner, $tenant, $branch, 'roles.create'));
        self::assertSame($adminMembership, $auth->require($admin, $tenant, $branch, 'roles.view'));

        // Before the fix OWNER returned its membership despite the foreign branch.
        foreach ([$owner, $admin] as $actor) {
            try {
                $auth->require($actor, $tenant, $foreign, 'roles.view');
                self::fail('require() authorized an unrelated tenant branch.');
            } catch (AccessDeniedException $exception) {
                self::assertSame('La sede no pertenece a la empresa activa.', $exception->getMessage());
            }
        }
    }

    public function testAdminWithoutAssignedPermissionStillFailsClosed(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $auth = static::getContainer()->get(BranchAuthorization::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(BranchAuthorization::class, $auth);

        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Tenant C', 'auth-c-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal', null, true);
        $user = new User('auth-restricted-'.$suffix.'@example.test', 'Admin limitado');
        $membership = new Membership($tenant, $user, 'ADMIN');
        foreach ([$tenant, $branch, $user, $membership] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $this->expectException(AccessDeniedException::class);
        $auth->require($user, $tenant, $branch, 'roles.create');
    }
}

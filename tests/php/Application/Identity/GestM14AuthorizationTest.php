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

/** GEST-M14-S01/S02: exercise the real require() with a foreign tenant branch. */
final class GestM14AuthorizationTest extends KernelTestCase
{
    public function testOwnerShortcutCannotBypassTenantBoundary(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $auth = static::getContainer()->get(BranchAuthorization::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(BranchAuthorization::class, $auth);

        $nonce = bin2hex(random_bytes(5));
        $tenant = new Tenant('Auth principal', 'unit-a-'.$nonce);
        $other = new Tenant('Auth ajeno', 'unit-b-'.$nonce);
        $local = new Branch($tenant, 'Propia', 'own', null, true);
        $foreign = new Branch($other, 'Ajena', 'foreign', null, true);
        $owner = new User('unit-owner-'.$nonce.'@example.test', 'Propietario');
        $admin = new User('unit-admin-'.$nonce.'@example.test', 'Delegado');
        $ownerMember = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $adminMember = new Membership($tenant, $admin, 'ADMIN');
        $viewer = new Role($tenant, 'Consulta', ['roles.view']);

        $entities = [$tenant, $other, $local, $foreign, $owner, $admin, $ownerMember, $adminMember, $viewer];
        array_walk($entities, static fn (object $entity) => $em->persist($entity));
        $em->persist(new BranchRoleAssignment($adminMember, $local, $viewer));
        $em->flush();

        self::assertSame($ownerMember, $auth->require($owner, $tenant, $local, 'roles.create'));
        self::assertSame($adminMember, $auth->require($admin, $tenant, $local, 'roles.view'));

        foreach ([
            [$owner, $foreign, 'roles.view', 'La sede no pertenece a la empresa activa.'],
            [$admin, $foreign, 'roles.view', 'La sede no pertenece a la empresa activa.'],
            [$admin, $local, 'roles.create', 'No tienes permiso para realizar esta acción.'],
        ] as [$actor, $branch, $permission, $reason]) {
            try {
                $auth->require($actor, $tenant, $branch, $permission);
                self::fail('require() granted a forbidden branch or permission.');
            } catch (AccessDeniedException $exception) {
                self::assertSame($reason, $exception->getMessage());
            }
        }
    }
}

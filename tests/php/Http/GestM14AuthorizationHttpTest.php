<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Authenticated HTTP regression for GEST-M14-S01/S02; synthetic tenants only. */
final class GestM14AuthorizationHttpTest extends WebTestCase
{
    public function testOwnerAndAdminCannotReadOrMutateForeignTenantBranch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('M14 A', 'm14-a-'.$suffix);
        $foreignTenant = new Tenant('M14 B', 'm14-b-'.$suffix);
        $branch = new Branch($tenant, 'Propia', 'own', null, true);
        $foreign = new Branch($foreignTenant, 'Ajena', 'foreign', null, true);
        $owner = new User('m14-owner-'.$suffix.'@example.test', 'Propietario');
        $admin = new User('m14-admin-'.$suffix.'@example.test', 'Delegado');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $adminMembership = new Membership($tenant, $admin, 'ADMIN');
        $viewRole = new Role($tenant, 'Consulta M14', ['roles.view']);

        foreach ([
            $tenant, $foreignTenant, $branch, $foreign, $owner, $admin,
            $ownerMembership, $adminMembership, $viewRole,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new BranchRoleAssignment($adminMembership, $branch, $viewRole));
        $em->flush();

        $roleCount = $em->getRepository(Role::class)->count([]);
        $auditCount = $em->getRepository(AuditEvent::class)->count([]);

        foreach ([$owner, $admin] as $actor) {
            $client->loginUser($actor);
            $csrf = $this->csrf($client);

            // Positive control: granted roles.view works for the current tenant.
            $client->request('GET', '/api/v1/branches/'.$branch->id().'/roles');
            self::assertResponseStatusCodeSame(200);

            $client->request('GET', '/api/v1/branches/'.$foreign->id().'/roles');
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString(
                $foreign->id(),
                (string) $client->getResponse()->getContent(),
            );

            $client->jsonRequest(
                'POST',
                '/api/v1/branches/'.$foreign->id().'/roles',
                ['name' => 'Escalamiento extranjero', 'permissions' => ['roles.view']],
                ['HTTP_X_CSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(404);
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($foreign->id(), $body);
            self::assertStringNotContainsString($foreignTenant->id(), $body);
        }

        self::assertSame($roleCount, $em->getRepository(Role::class)->count([]));
        self::assertSame($auditCount, $em->getRepository(AuditEvent::class)->count([]));
    }

    public function testDelegatedAdminWithoutCreatePermissionIsDeniedWithoutWrite(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('M14 Restringido', 'm14-restricted-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal', null, true);
        $admin = new User('m14-restricted-'.$suffix.'@example.test', 'Administrador');
        $membership = new Membership($tenant, $admin, 'ADMIN');
        $viewRole = new Role($tenant, 'Solo consulta', ['roles.view']);
        foreach ([$tenant, $branch, $admin, $membership, $viewRole] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new BranchRoleAssignment($membership, $branch, $viewRole));
        $em->flush();

        $roles = $em->getRepository(Role::class)->count([]);
        $audits = $em->getRepository(AuditEvent::class)->count([]);

        $client->loginUser($admin);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/roles',
            ['name' => 'Sin autorización', 'permissions' => ['roles.view']],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(403);
        self::assertSame($roles, $em->getRepository(Role::class)->count([]));
        self::assertSame($audits, $em->getRepository(AuditEvent::class)->count([]));
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }
}

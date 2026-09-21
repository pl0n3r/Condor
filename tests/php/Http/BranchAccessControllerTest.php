<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BranchAccessControllerTest extends WebTestCase
{
    public function testOwnerCanCreateRoleAndAssignItWithinTenant(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $target = new User(
            'target-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario objetivo',
        );
        $membership = new Membership($tenant, $target, 'ADMIN');
        $entityManager->persist($target);
        $entityManager->persist($membership);
        $entityManager->flush();

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/roles',
            [
                'name' => 'Gestor catálogo',
                'permissions' => ['catalog.view', 'catalog.update'],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $roleId = $payload['role']['id'];

        $client->request(
            'PUT',
            '/api/v1/branches/'.$branch->id().'/memberships/'.
            $membership->id().'/roles/'.$roleId,
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $assignment = $entityManager
            ->getRepository(BranchRoleAssignment::class)
            ->findOneBy([
                'membership' => $membership,
                'branch' => $branch,
            ]);
        self::assertInstanceOf(BranchRoleAssignment::class, $assignment);
        self::assertSame($roleId, $assignment->role()->id());
    }

    public function testDelegatedAdminCannotGrantPermissionsTheyDoNotHave(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $branch, $admin, $membership] = $this->tenantWithUser(
            $entityManager,
            'ADMIN',
            true,
        );
        $delegator = new Role(
            $tenant,
            'Gestor de roles',
            ['roles.create'],
        );
        $entityManager->persist($delegator);
        $entityManager->persist(
            new BranchRoleAssignment($membership, $branch, $delegator),
        );
        $entityManager->flush();

        $client->loginUser($admin);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/roles',
            [
                'name' => 'Escalamiento',
                'permissions' => ['roles.create', 'users.update'],
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertNull(
            $entityManager->getRepository(Role::class)->findOneBy([
                'tenant' => $tenant,
                'name' => 'Escalamiento',
            ]),
        );
    }

    public function testCrossTenantIdsAreNotResolved(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [, , $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $otherTenant = new Tenant(
            'Otra empresa',
            'otra-'.bin2hex(random_bytes(4)),
        );
        $otherBranch = new Branch(
            $otherTenant,
            'Otra sede',
            'otra',
            null,
            true,
        );
        $entityManager->persist($otherTenant);
        $entityManager->persist($otherBranch);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/api/v1/branches/'.$otherBranch->id().'/roles',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testContextReturnsBranchesAndEffectivePermissions(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $secondary = new Branch(
            $tenant,
            'Secundaria',
            'secundaria',
        );
        $entityManager->persist($secondary);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/api/v1/context?branch='.$secondary->id(),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            $secondary->id(),
            $payload['active_branch']['id'],
        );
        self::assertCount(2, $payload['branches']);
        self::assertContains('roles.create', $payload['permissions']);
        self::assertSame($tenant->id(), $payload['tenant']['id']);
        self::assertNotSame(
            $branch->id(),
            $payload['active_branch']['id'],
        );
    }

    public function testNonOwnerOnlySeesAssignedBranchesInContext(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $assigned, $user, $membership] = $this->tenantWithUser(
            $entityManager,
            'ADMIN',
            true,
        );
        $hidden = new Branch($tenant, 'Oculta', 'oculta');
        $role = new Role($tenant, 'Consulta', ['catalog.view']);

        $entityManager->persist($hidden);
        $entityManager->persist($role);
        $entityManager->persist(
            new BranchRoleAssignment($membership, $assigned, $role),
        );
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/api/v1/context');

        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertCount(1, $payload['branches']);
        self::assertSame($assigned->id(), $payload['branches'][0]['id']);
        self::assertSame($assigned->id(), $payload['active_branch']['id']);

        $client->request(
            'GET',
            '/api/v1/context?branch='.$hidden->id(),
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testContextKeepsTenantWhenNoBranchIsAccessible(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, , $user] = $this->tenantWithUser(
            $entityManager,
            'ADMIN',
        );

        $client->loginUser($user);
        $client->request('GET', '/api/v1/context');

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('branch_scope_required', $payload['error']);
        self::assertSame(
            'No tienes una sede asignada en esta empresa.',
            $payload['message'],
        );
        self::assertSame(403, $payload['status']);
        self::assertIsString($payload['request_id']);
        self::assertSame(
            $tenant->id(),
            $payload['details']['tenant']['id'],
        );
        self::assertSame(
            $tenant->name(),
            $payload['details']['tenant']['name'],
        );
        self::assertArrayNotHasKey('active_branch', $payload);
    }

    public function testRoleMutationRejectsUnexpectedFields(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/roles',
            [
                'name' => 'Rol inválido',
                'permissions' => ['catalog.view'],
                'tenant_id' => $tenant->id(),
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('validation_error', $payload['error']);
        self::assertSame(422, $payload['status']);
        self::assertIsString($payload['request_id']);
        self::assertNull(
            $entityManager->getRepository(Role::class)->findOneBy([
                'tenant' => $tenant,
                'name' => 'Rol inválido',
            ]),
        );
    }

    public function testMutationWithoutCsrfIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/roles',
            ['name' => 'Sin CSRF', 'permissions' => []],
        );

        self::assertResponseStatusCodeSame(403);
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $token = $crawler
            ->filter('#condor-admin-root')
            ->attr('data-access-token');

        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array{0: Tenant, 1: Branch, 2: User, 3?: Membership}
     */
    private function tenantWithUser(
        EntityManagerInterface $entityManager,
        string $roleKey,
        bool $includeMembership = false,
    ): array {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant(
            'Empresa '.$suffix,
            'empresa-'.$suffix,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal',
            null,
            true,
        );
        $user = new User(
            'user-'.$suffix.'@example.test',
            'Usuario',
        );
        $membership = new Membership($tenant, $user, $roleKey);

        foreach ([$tenant, $branch, $user, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        if ($includeMembership) {
            return [$tenant, $branch, $user, $membership];
        }

        return [$tenant, $branch, $user];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Platform\ModuleCatalog;
use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModuleAccessTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testTenantAndUserOverridesEnforceServerAndMenuAndAreAudited(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $owner = new User('modules-platform-'.$suffix.'@example.test', 'Operador plataforma', [User::ROLE_PLATFORM_OWNER]);
        $member = new User('modules-member-'.$suffix.'@example.test', 'Administradora empresa');
        $outsider = new User('modules-outsider-'.$suffix.'@example.test', 'Usuario ajeno');
        $tenant = new Tenant('Empresa módulos '.$suffix, 'modules-'.$suffix);
        $other = new Tenant('Empresa ajena '.$suffix, 'modules-other-'.$suffix);
        $branch = new Branch($tenant, 'Sede principal', 'principal', default: true);
        $membership = new Membership($tenant, $member, Membership::ROLE_OWNER);
        $otherMembership = new Membership($other, $outsider, Membership::ROLE_OWNER);
        foreach ([$owner, $member, $outsider, $tenant, $other, $branch, $membership, $otherMembership] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $api = '/adminpl0n3r/api/tenants/'.$tenant->id().'/modules';
        $inventory = '/api/v1/branches/'.$branch->id().'/inventory';

        $client->loginUser($member);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertContains('inventory', explode(',', (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        $client->jsonRequest('PUT', $api, ['changes' => [['key' => 'inventory', 'enabled' => false]]]);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->request('GET', $api);
        self::assertResponseIsSuccessful();
        $initial = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($tenant->id(), $initial['tenant']['id']);
        self::assertContains('inventory', array_column($initial['modules'], 'key'));

        $client->jsonRequest('PUT', $api, ['changes' => [['key' => 'inventory', 'enabled' => false]]]);
        self::assertResponseStatusCodeSame(403, 'Owner edits require management CSRF.');
        $client->jsonRequest('PUT', $api, ['changes' => [['key' => 'inventory', 'enabled' => false]]], [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();

        $client->loginUser($member);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertNotContains('inventory', explode(',', (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        $client->request('GET', $inventory);
        self::assertResponseStatusCodeSame(403, 'Disabled modules are denied before their controllers run.');

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->jsonRequest('PUT', $api, ['changes' => [['key' => 'inventory', 'enabled' => true]]], [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('PUT', $api.'/users', [
            'changes' => [['user_id' => $outsider->id(), 'key' => 'inventory', 'enabled' => false]],
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422, 'An owner cannot mutate access of a different tenant member.');

        $client->jsonRequest('PUT', $api.'/users', [
            'changes' => [['user_id' => $member->id(), 'key' => 'inventory', 'enabled' => false]],
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();

        $client->loginUser($member);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertNotContains('inventory', explode(',', (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        $client->request('GET', $inventory);
        self::assertResponseStatusCodeSame(403, 'User module override cannot re-enable tenant APIs.');

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->jsonRequest('PUT', $api.'/users', [
            'changes' => [['user_id' => $member->id(), 'key' => 'inventory', 'enabled' => true]],
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();

        $client->loginUser($member);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertContains('inventory', explode(',', (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));

        self::assertSame(2, $manager->getRepository(PlatformAuditEvent::class)->count([
            'actorUserId' => $owner->id(), 'tenantId' => $tenant->id(),
            'action' => 'owner.module.tenant.updated',
        ]));
        self::assertSame(2, $manager->getRepository(PlatformAuditEvent::class)->count([
            'actorUserId' => $owner->id(), 'tenantId' => $tenant->id(),
            'action' => 'owner.module.user.updated',
        ]));
    }

    public function testSupportOwnerCanSeeDisabledModuleWithoutGrantingMembership(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $owner = new User('modules-support-'.$suffix.'@example.test', 'Soporte plataforma', [User::ROLE_PLATFORM_OWNER]);
        $tenant = new Tenant('Soporte módulo '.$suffix, 'modules-support-'.$suffix);
        $branch = new Branch($tenant, 'Sede soporte', 'principal', default: true);
        foreach ([$owner, $tenant, $branch] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $api = '/adminpl0n3r/api/tenants/'.$tenant->id().'/modules';
        $client->jsonRequest('PUT', $api, ['changes' => [
            ['key' => 'inventory', 'enabled' => false],
        ]], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(), 'branch_id' => $branch->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertContains('inventory', explode(',', (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        self::assertSame(0, $manager->getRepository(Membership::class)->count(['user' => $owner]));
    }

    public function testCatalogUsesCanonicalPrefixBoundaries(): void
    {
        self::assertSame('inventory', ModuleCatalog::forApiPath('/api/v1/branches/ABC/inventory'));
        self::assertSame('stock-alerts', ModuleCatalog::forApiPath('/api/v1/branches/ABC/inventory/stock-alerts'));
        self::assertSame('stock-transfers', ModuleCatalog::forApiPath('/api/v1/branches/ABC/inventory/branch-transfers'));
        self::assertSame('catalog', ModuleCatalog::forApiPath('/api/catalog/import/preview'));
        self::assertNull(ModuleCatalog::forApiPath('/api/v1/branches/ABC/inventory-unsafe'));
        self::assertNull(ModuleCatalog::forApiPath('/api/v1/context'));
        self::assertNull(ModuleCatalog::forApiPath('/api/health'));
    }

    public function testEmployeesModuleCannotBeRevivedByOverridesOrSupport(): void
    {
        self::assertTrue(ModuleCatalog::exists('employees-management'));
        self::assertFalse(ModuleCatalog::isAvailable('employees-management'));
        self::assertSame('employees-management', ModuleCatalog::forApiPath('/api/v1/hr/employees'));
        self::assertNull(ModuleCatalog::forApiPath('/api/v1/hr/employees-unsafe'));

        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $owner = new User('employees-support-'.$suffix.'@example.test', 'Soporte plataforma', [User::ROLE_PLATFORM_OWNER]);
        $member = new User('employees-member-'.$suffix.'@example.test', 'Administradora empresa');
        $tenant = new Tenant('Empresa empleados '.$suffix, 'employees-'.$suffix);
        $branch = new Branch($tenant, 'Sede principal', 'principal', default: true);
        $membership = new Membership($tenant, $member, Membership::ROLE_OWNER);
        foreach ([$owner, $member, $tenant, $branch, $membership] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($member);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertNotContains('employees-management', explode(',',
            (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        $client->jsonRequest('POST', '/api/v1/hr/employees', ['code' => 'SINTETICO']);
        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('module_unavailable', $payload['error'] ?? null);
        self::assertStringContainsString('no está disponible', (string) ($payload['message'] ?? ''));

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $api = '/adminpl0n3r/api/tenants/'.$tenant->id().'/modules';
        $client->jsonRequest('PUT', $api, ['changes' => [
            ['key' => 'employees-management', 'enabled' => true],
        ]], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertContains($client->getResponse()->getStatusCode(), [200, 403, 422]);

        $client->loginUser($member);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertNotContains('employees-management', explode(',',
            (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        $access = static::getContainer()->get(\App\Application\Platform\ModuleAccess::class);
        self::assertFalse($access->canAccess($member, $tenant, 'employees-management'));
        self::assertTrue($access->disabledByOwner($member, $tenant, 'employees-management'));

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(), 'branch_id' => $branch->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertNotContains('employees-management', explode(',',
            (string) $crawler->filter('#condor-admin-root')->attr('data-enabled-modules')));
        self::assertFalse($access->canAccess($owner, $tenant, 'employees-management'));
        self::assertTrue($access->disabledByOwner($owner, $tenant, 'employees-management'));
    }

}

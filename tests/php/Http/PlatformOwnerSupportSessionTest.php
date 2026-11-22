<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerSupportSessionTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testOwnerSupportIsTemporaryTenantScopedAndAudited(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $actor = new User(
            'support-owner-'.$suffix.'@example.test',
            'Propietario soporte',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant('Empresa Soporte '.$suffix, 'soporte-'.$suffix);
        $branch = new Branch($tenant, 'Sede Soporte', 'principal', default: true);
        $other = new Tenant('Otra Empresa '.$suffix, 'soporte-otra-'.$suffix);
        $otherBranch = new Branch($other, 'Otra sede', 'principal', default: true);
        foreach ([$actor, $tenant, $branch, $other, $otherBranch] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($actor);
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/adminpl0n3r');

        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(),
        ]);
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(),
            'branch_id' => $otherBranch->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(),
            'branch_id' => $branch->id(),
            'module' => 'human-resources-management',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $started = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('/admin#human-resources-management', $started['href']);

        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('#condor-admin-root')->attr('data-support-mode'));
        self::assertSame($tenant->name(), $crawler->filter('#condor-admin-root')->attr('data-support-tenant-name'));

        $client->request('GET', '/api/v1/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($tenant->id(), $context['tenant']['id']);
        self::assertSame($branch->id(), $context['active_branch']['id']);
        self::assertContains('roles.create', $context['permissions']);
        self::assertSame(0, $manager->getRepository(Membership::class)->count([
            'user' => $actor,
        ]), 'Support never persists a tenant membership.');

        $writeCsrf = $this->csrfToken($client, 'branch_access');
        $client->jsonRequest(
            'POST', '/api/v1/branches/'.$branch->id().'/roles',
            ['name' => 'Soporte '.$suffix, 'permissions' => ['catalog.view']],
            ['HTTP_X_CSRF_TOKEN' => $writeCsrf],
        );
        self::assertResponseStatusCodeSame(201);
        self::assertGreaterThanOrEqual(1, $manager
            ->getRepository(PlatformAuditEvent::class)->count([
                'actorUserId' => $actor->id(),
                'tenantId' => $tenant->id(),
                'action' => 'owner.support.write',
            ]));

        $client->jsonRequest('POST', '/adminpl0n3r/api/support/close', [], [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/adminpl0n3r');
        self::assertSame(1, $manager->getRepository(PlatformAuditEvent::class)->count([
            'actorUserId' => $actor->id(),
            'tenantId' => $tenant->id(),
            'action' => 'owner.support.ended',
        ]));
    }

    public function testPlatformStaffCannotImpersonateOwnerSupport(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $staff = new User(
            'support-staff-'.$suffix.'@example.test',
            'Staff sin alcance',
            [User::ROLE_PLATFORM_STAFF],
        );
        $tenant = new Tenant('Tenant staff '.$suffix, 'staff-soporte-'.$suffix);
        $manager->persist($staff);
        $manager->persist($tenant);
        $manager->flush();

        $client->loginUser($staff);
        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(),
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/adminpl0n3r/api/context?tenant='.$tenant->id());
        self::assertResponseStatusCodeSame(403);
    }
}

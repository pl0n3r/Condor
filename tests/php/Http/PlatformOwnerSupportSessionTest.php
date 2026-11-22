<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Platform\OwnerSupportSession;
use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
        $secondBranch = new Branch($tenant, 'Sede B', 'secundaria');
        $other = new Tenant('Otra Empresa '.$suffix, 'soporte-otra-'.$suffix);
        $otherBranch = new Branch($other, 'Otra sede', 'principal', default: true);
        foreach ([$actor, $tenant, $branch, $secondBranch, $other, $otherBranch] as $entity) {
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
        // A write into sede B must not be audited against the sede A chosen
        // when support began; the target is decided by branch authorization.
        $client->jsonRequest(
            'POST', '/api/v1/branches/'.$secondBranch->id().'/roles',
            ['name' => 'Soporte B '.$suffix, 'permissions' => ['catalog.view']],
            ['HTTP_X_CSRF_TOKEN' => $writeCsrf],
        );
        self::assertResponseStatusCodeSame(201);
        $writes = $manager->getRepository(PlatformAuditEvent::class)->findBy([
            'actorUserId' => $actor->id(),
            'tenantId' => $tenant->id(),
            'action' => 'owner.support.write',
        ]);
        self::assertCount(2, $writes);
        $contextProperty = new \ReflectionProperty(PlatformAuditEvent::class, 'context');
        $auditedBranches = array_map(
            static fn (PlatformAuditEvent $event): mixed =>
                $contextProperty->getValue($event)['branch_id'] ?? null,
            $writes,
        );
        self::assertContains($branch->id(), $auditedBranches);
        self::assertContains($secondBranch->id(), $auditedBranches);

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
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/adminpl0n3r/api/context?tenant='.$tenant->id());
        self::assertResponseStatusCodeSame(403);
    }
    public function testAnAuditFailureNeverGrantsSupport(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('flush')
            ->willThrowException(new \RuntimeException('audit unavailable'));
        $request = Request::create('/adminpl0n3r/api/support', 'POST');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);
        $support = new OwnerSupportSession(
            $requests,
            $manager,
            $this->createMock(TokenStorageInterface::class),
        );
        $suffix = bin2hex(random_bytes(5));
        $actor = new User(
            'audit-failure-'.$suffix.'@example.test',
            'Owner audit fail',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant('Empresa audit '.$suffix, 'audit-'.$suffix);

        try {
            $support->start($actor, $tenant);
            self::fail('The support start must fail when audit persistence fails.');
        } catch (\RuntimeException $error) {
            self::assertSame('audit unavailable', $error->getMessage());
        }

        self::assertNull($session->get('condor.platform_owner_support'));
    }

    public function testWriteStillAuditedWhenSessionExpiresAfterAuthorization(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $actor = new User(
            'support-expiry-'.$suffix.'@example.test',
            'Owner expiring request',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant('Empresa expiry '.$suffix, 'expiry-'.$suffix);
        $branch = new Branch($tenant, 'Sede expiry', 'principal', default: true);
        foreach ([$actor, $tenant, $branch] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($actor);
        $client->request('GET', '/adminpl0n3r');
        $csrf = $this->csrfToken($client, 'platform_tenant_management');
        $client->jsonRequest('POST', '/adminpl0n3r/api/support', [
            'tenant_id' => $tenant->id(),
            'branch_id' => $branch->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();

        // KernelBrowser normally reboots the kernel between requests, which
        // would discard the test listener before the write response arrives.
        // Keep this kernel for the synthetic expiry boundary regression.
        $client->disableReboot();
        // After the controller has authorized and committed the write, but
        // before the response auditor runs at priority -64, expire the lease.
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event): void {
            if ($event->getRequest()->attributes->get('_route') !== 'api_branch_roles_create'
                || $event->getResponse()->getStatusCode() !== 201) {
                return;
            }
            $session = $event->getRequest()->getSession();
            $data = $session->get('condor.platform_owner_support');
            if (is_array($data)) {
                $data['expires_at'] = time() - 1;
                $session->set('condor.platform_owner_support', $data);
            }
        }, -60);

        $writeCsrf = $this->csrfToken($client, 'branch_access');
        $client->jsonRequest(
            'POST', '/api/v1/branches/'.$branch->id().'/roles',
            ['name' => 'Expiring '.$suffix, 'permissions' => ['catalog.view']],
            ['HTTP_X_CSRF_TOKEN' => $writeCsrf],
        );
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $manager->getRepository(PlatformAuditEvent::class)->count([
            'actorUserId' => $actor->id(),
            'tenantId' => $tenant->id(),
            'action' => 'owner.support.write',
        ]));
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/adminpl0n3r');
    }
}

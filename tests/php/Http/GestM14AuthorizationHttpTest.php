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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Negative authenticated HTTP contract for M14 with synthetic tenant pairs. */
final class GestM14AuthorizationHttpTest extends WebTestCase
{
    public function testTenantAndRoleBoundariesRejectReadsWritesWithoutDisclosure(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);

        $id = bin2hex(random_bytes(5));
        $a = new Tenant('M14 empresa principal', 'http-a-'.$id);
        $b = new Tenant('M14 empresa ajena', 'http-b-'.$id);
        $own = new Branch($a, 'Principal', 'own', null, true);
        $external = new Branch($b, 'Externa', 'external', null, true);
        $owner = new User('http-owner-'.$id.'@example.test', 'Propietario');
        $delegate = new User('http-delegate-'.$id.'@example.test', 'Delegado');
        $ownerAccess = new Membership($a, $owner, Membership::ROLE_OWNER);
        $delegateAccess = new Membership($a, $delegate, 'ADMIN');
        $reader = new Role($a, 'Consulta', ['roles.view']);

        foreach ([$a, $b, $own, $external, $owner, $delegate, $ownerAccess, $delegateAccess, $reader] as $record) {
            $database->persist($record);
        }
        $database->persist(new BranchRoleAssignment($delegateAccess, $own, $reader));
        $database->flush();

        $roleRepo = $database->getRepository(Role::class);
        $auditRepo = $database->getRepository(AuditEvent::class);
        $initialRoles = $roleRepo->count([]);
        $initialEvents = $auditRepo->count([]);
        $foreignUrl = '/api/v1/branches/'.$external->id().'/roles';

        foreach ([$owner, $delegate] as $actor) {
            $browser->loginUser($actor);
            $csrf = $this->csrf($browser);
            $browser->request('GET', '/api/v1/branches/'.$own->id().'/roles');
            self::assertResponseStatusCodeSame(200);

            $browser->request('GET', $foreignUrl);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($external->id(), (string) $browser->getResponse()->getContent());

            $browser->jsonRequest(
                'POST', $foreignUrl,
                ['name' => 'Intento cross-tenant', 'permissions' => ['roles.view']],
                ['HTTP_X_CSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(404);
            $response = (string) $browser->getResponse()->getContent();
            self::assertStringNotContainsString($external->id(), $response);
            self::assertStringNotContainsString($b->id(), $response);
        }

        $browser->loginUser($delegate);
        $browser->jsonRequest(
            'POST', '/api/v1/branches/'.$own->id().'/roles',
            ['name' => 'Elevación', 'permissions' => ['roles.view']],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($browser)],
        );
        self::assertResponseStatusCodeSame(403);
        self::assertSame($initialRoles, $roleRepo->count([]));
        self::assertSame($initialEvents, $auditRepo->count([]));
    }

    private function csrf(KernelBrowser $browser): string
    {
        $html = $browser->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $html->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }
}

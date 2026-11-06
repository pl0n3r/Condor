<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** #1582: HTTP real y autenticado; solo fixtures M13 S03. */
final class QualityControllerHttpTest extends WebTestCase
{
    public function testInternalComplaintIsScopedPermissionedAndCsrfProtected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Calidad QA A '.$suffix, 'quality-a-'.$suffix);
        $tenantB = new Tenant('Calidad QA B '.$suffix, 'quality-b-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Calidad A '.$suffix, null, true);
        $legalB = new LegalEntity($tenantB, 'Calidad B '.$suffix, null, true);
        $branchA = new Branch($tenantA, 'Sede QA A', 'qa-a-'.$suffix, $legalA, true);
        $branchB = new Branch($tenantB, 'Sede QA B', 'qa-b-'.$suffix, $legalB, true);
        $owner = new User('qa-owner-'.$suffix.'@example.test', 'Dueño de prueba');
        $viewer = new User('qa-viewer-'.$suffix.'@example.test', 'Lector de prueba');
        $foreign = new User('qa-foreign-'.$suffix.'@example.test', 'Otro tenant');
        $ownerMember = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $viewerMember = new Membership($tenantA, $viewer, 'ADMIN');
        $foreignMember = new Membership($tenantB, $foreign, Membership::ROLE_OWNER);
        $readOnly = new Role($tenantA, 'Consulta QA '.$suffix, ['settings.view']);
        foreach ([$tenantA, $tenantB, $legalA, $legalB, $branchA, $branchB,
            $owner, $viewer, $foreign, $ownerMember, $viewerMember, $foreignMember,
            $readOnly] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new BranchRoleAssignment($viewerMember, $branchA, $readOnly));
        $em->flush();

        $baseA = '/api/v1/branches/'.$branchA->id().'/quality/records';
        $baseB = '/api/v1/branches/'.$branchB->id().'/quality/records';
        $record = [
            'record_ref' => 'fixture:quality-record/queja',
            'source_ref' => 'fixture:quality-source/demo',
            'kind' => 'QUEJA_INTERNA',
        ];

        $client->request('GET', $baseA);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403, 302]);
        $client->loginUser($owner);
        $token = $this->csrf($client);
        $headers = ['HTTP_X_CSRF_TOKEN' => $token];
        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(200);
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertSame([], self::payload($client)['records']);
        $client->request('GET', $baseB);
        self::assertResponseStatusCodeSame(404);

        // Los rechazos no crean quejas ni cambian el estado, tampoco con tenant ajeno.
        $client->jsonRequest('POST', $baseB, $record, $headers);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $baseA, $record);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $baseA, [...$record, 'tenant_id' => $tenantB->id()], $headers);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $baseA, [...$record, 'source_ref' => 'persona@example.test'], $headers);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $baseA, [], [], $headers, '{');
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', $baseA, $record, $headers);
        self::assertResponseStatusCodeSame(201);
        $created = self::payload($client);
        self::assertTrue($created['synthetic_only']);
        self::assertSame('QUEJA_INTERNA', $created['record']['kind']);
        self::assertSame('PENDIENTE', $created['record']['state']);
        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, self::payload($client)['records']);

        $state = $baseA.'/queja/state';
        $client->jsonRequest('PATCH', $state, ['state' => 'EN_REVISION']);
        self::assertResponseStatusCodeSame(403);
        foreach (['CERRADO', 'APROBADO', 'REALIZADO'] as $illegal) {
            $client->jsonRequest('PATCH', $state, ['state' => $illegal], $headers);
            self::assertResponseStatusCodeSame(422);
        }
        $client->jsonRequest('PATCH', $state, ['state' => 'EN_REVISION'], $headers);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('EN_REVISION', self::payload($client)['record']['state']);
        $client->jsonRequest('PATCH', $state, ['state' => 'REQUIERE_REVISION'], $headers);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('REQUIERE_REVISION', self::payload($client)['record']['state']);
        self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
            'tenant' => $tenantA, 'action' => 'quality.synthetic.created',
        ]));

        $client->loginUser($viewer);
        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('POST', $baseA, $record, $headers);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PATCH', $state, ['state' => 'EN_REVISION'], $headers);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($foreign);
        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $baseB);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::payload($client)['records']);
    }

    private function csrf(KernelBrowser $client): string
    {
        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }

    /** @return array<string,mixed> */
    private static function payload(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}

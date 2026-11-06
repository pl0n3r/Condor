<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Documents\VerifiedSignature;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M08 HTTP with real Symfony firewall/tenant/CSRF and synthetic fixtures only. */
final class DocumentsControllerHttpTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testAnonymousAndForeignScopeAreDenied(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [, , $branchA, $ownerA] = $this->fixture($em);
        [, , $branchB] = $this->fixture($em);
        $base = '/api/v1/branches/'.$branchA->id().'/documents';

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($ownerA);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->body($client)['documents']);
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/documents');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $base.'/missing');
        self::assertResponseStatusCodeSame(404);

        // A branch with no legal entity must not expose another scope's data.
        $orphan = new Branch($branchA->tenant(), 'Sin entidad', 'orphan', null);
        $em->persist($orphan);
        $em->flush();
        $client->request('GET', '/api/v1/branches/'.$orphan->id().'/documents');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPostRequiresCsrfAndCatalogPermissionsWithoutEscalation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, , $branch, $owner] = $this->fixture($em);
        $base = '/api/v1/branches/'.$branch->id().'/documents';
        $client->loginUser($owner);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $csrf = $this->csrfToken($client, 'branch_access');
        $payload = ['reference' => 'fixture:doc/alpha', 'classification' => 'INTERNA'];
        $client->jsonRequest('POST', $base, $payload);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base, $payload, ['HTTP_X_CSRF_TOKEN' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base, [...$payload, 'tenant_id' => $tenant->id()],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);

        $viewer = new User('m08-viewer-'.bin2hex(random_bytes(5)).'@example.test', 'Viewer');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'M08 lectura', ['settings.view']);
        foreach ([$viewer, $membership, $role,
            new BranchRoleAssignment($membership, $branch, $role)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($viewer);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('POST', $base, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $base);
        self::assertSame([], $this->body($client)['documents']);
    }

    public function testOwnerCanExerciseRealRoutesWithoutLegalSignature(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [, , $branch, $owner] = $this->fixture($em);
        $client->loginUser($owner);
        $base = '/api/v1/branches/'.$branch->id().'/documents';
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('NO_VERIFICABLE', $this->body($client)['signature_gate']['status']);
        $csrf = $this->csrfToken($client, 'branch_access');
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];
        $client->jsonRequest('POST', $base,
            ['reference' => 'fixture:doc/alpha', 'classification' => 'RESERVADA'], $headers);
        self::assertResponseStatusCodeSame(201);

        $client->request('GET', $base.'/alpha');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('fixture:doc/alpha', $this->body($client)['document']['reference']);
        $client->request('GET', $base.'/alpha/trace');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['CREADO'], array_column($this->body($client)['events'], 'action'));

        $client->jsonRequest('POST', $base.'/alpha/evidence', ['evidence_ref' => 'fixture:evidence/demo']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $base.'/alpha/evidence', ['evidence_ref' => 'somebody@example.test'], $headers);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $base.'/alpha/evidence', ['evidence_ref' => 'fixture:evidence/demo'], $headers);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->body($client)['legal_signature']);
        $client->jsonRequest('POST', $base.'/alpha/request-review', [], $headers);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->body($client)['can_close']);
        $client->request('GET', $base.'/alpha/trace');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['CREADO', 'EVIDENCIA_ADJUNTADA', 'REVISION_SOLICITADA'],
            array_column($this->body($client)['events'], 'action'));

        $client->request('GET', $base.'/export');
        self::assertResponseStatusCodeSame(200);
        $export = $this->body($client);
        self::assertSame(['fixture:doc/alpha'], array_column($export['documents'], 'reference'));
        self::assertTrue($export['synthetic']);
        self::assertFalse($export['legal_signature']);
        self::assertFalse($export['can_close']);
        self::assertTrue($export['signature_gate']['requires_legal_decision']);
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('NO_VERIFICABLE', VerifiedSignature::STATUS);
        try {
            VerifiedSignature::assertAuthorized();
            self::fail('El flujo jurídico no debe habilitar firmas reales.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('Firma jurídica bloqueada', $exception->getMessage());
        }
    }

    public function testReactPanelHasServerStatesAndFailClosedLegalStatus(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/frontend/admin/DocumentsManagement.tsx');
        self::assertIsString($source);
        foreach ([
            'HttpDocumentsPanel', 'useEffect(', 'credentials:', 'state.kind === \'loading\'',
            'state.kind === \'denied\'', 'state.kind === \'error\'',
            'state.documents.length === 0', "base + '/export'",
            "base + '/' + encodeURIComponent(key) + '/trace'",
            "base + '/' + encodeURIComponent(key) + '/evidence'",
            "body.signature_gate?.status !== 'NO_VERIFICABLE'",
            'X-CSRF-Token', 'import.meta.env.DEV',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }

    /** @return array{Tenant,LegalEntity,Branch,User} */
    private function fixture(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('M08 '.$suffix, 'm08-'.$suffix);
        $legal = new LegalEntity($tenant, 'M08 '.$suffix.' SAS', null, true);
        $branch = new Branch($tenant, 'Principal', 'm08-'.$suffix, $legal, true);
        $owner = new User('m08-'.$suffix.'@example.test', 'Propietario sintético');
        foreach ([$tenant, $legal, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $item) {
            $em->persist($item);
        }
        $em->flush();
        return [$tenant, $legal, $branch, $owner];
    }

    /** @return array<string,mixed> */
    private function body(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $value = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }
}

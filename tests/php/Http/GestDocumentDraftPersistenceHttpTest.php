<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** #2015: real disposable MariaDB, HTTP + fresh session, no production. */
final class GestDocumentDraftPersistenceHttpTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testSqlDraftSurvivesNewSessionAndDeniesForeignScopesAndReplay(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $db = $em->getConnection();
        self::assertSame('pdo_mysql', $db->getParams()['driver'] ?? null);
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(5));
            $tenant = new Tenant('Draft '.$suffix, 'draft-'.$suffix);
            $legal = new LegalEntity($tenant, 'Draft SAS '.$suffix, null, true);
            $otherLegal = new LegalEntity($tenant, 'Otra SAS '.$suffix, null);
            $branch = new Branch($tenant, 'Norte', 'draft-n-'.$suffix, $legal);
            $sibling = new Branch($tenant, 'Sur', 'draft-s-'.$suffix, $legal);
            $otherEntity = new Branch($tenant, 'Otra', 'draft-l-'.$suffix, $otherLegal);
            $foreignTenant = new Tenant('Otra '.$suffix, 'draft-foreign-'.$suffix);
            $foreignLegal = new LegalEntity($foreignTenant, 'Ajena '.$suffix, null, true);
            $foreignBranch = new Branch($foreignTenant, 'Ajena', 'draft-f-'.$suffix, $foreignLegal);
            $owner = new User('draft-'.$suffix.'@example.test', 'Editor sintético');
            $viewer = new User('draft-view-'.$suffix.'@example.test', 'Solo lectura');
            $viewerMembership = new Membership($tenant, $viewer, 'ADMIN');
            $viewerRole = new Role($tenant, 'Draft lector '.$suffix, ['settings.view']);
            foreach ([$tenant, $legal, $otherLegal, $branch, $sibling, $otherEntity,
                $foreignTenant, $foreignLegal, $foreignBranch, $owner,
                new Membership($tenant, $owner, Membership::ROLE_OWNER),
                $viewer, $viewerMembership, $viewerRole,
                new BranchRoleAssignment($viewerMembership, $branch, $viewerRole)] as $object) {
                $em->persist($object);
            }
            $em->flush();
            $base = '/api/v1/branches/'.$branch->id().'/documents';
            $payload = ['reference' => 'fixture:doc/alpha', 'classification' => 'INTERNA'];
            $client->loginUser($owner);
            $client->request('GET', $base);
            self::assertResponseStatusCodeSame(200);
            self::assertSame([], self::json($client)['documents']);
            $headers = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];

            $client->jsonRequest('POST', $base, $payload, $headers);
            self::assertResponseStatusCodeSame(201);
            $created = self::json($client)['document'];
            self::assertSame('fixture:doc/alpha', $created['reference']);
            self::assertSame('BORRADOR', $created['status']);
            self::assertSame(1, $created['version']);
            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $created['id']);

            $client->restart(); // Deliberately drops all cookies and server session state.
            $client->loginUser($owner);
            $client->request('GET', $base);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($created['id'], self::json($client)['documents'][0]['id']);
            $client->request('GET', $base.'/alpha');
            self::assertResponseStatusCodeSame(200);
            self::assertSame($created, self::json($client)['document']);

            foreach ([$sibling, $otherEntity, $foreignBranch] as $unrelated) {
                $client->request('GET', '/api/v1/branches/'.$unrelated->id().'/documents/alpha');
                self::assertResponseStatusCodeSame(404);
            }
            $client->request('GET', $base);
            $headers = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
            $client->jsonRequest('POST', $base, $payload, $headers);
            self::assertResponseStatusCodeSame(409);
            $client->jsonRequest('POST', $base, [...$payload, 'tenant_id' => $tenant->id()], $headers);
            self::assertResponseStatusCodeSame(422);
            $client->jsonRequest('POST', $base, $payload, ['HTTP_X_CSRF_TOKEN' => 'invalid']);
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($viewer);
            $client->request('GET', $base);
            self::assertResponseStatusCodeSame(200);
            $client->jsonRequest('POST', $base, $payload,
                ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')]);
            self::assertResponseStatusCodeSame(403);

            $row = $db->fetchAssociative('SELECT d.id, d.version FROM condor_document_record d '
                .'INNER JOIN condor_document_branch_link l ON d.record_ref = l.record_ref '
                .'AND d.tenant_ref = l.tenant_ref AND d.legal_entity_ref = l.legal_entity_ref '
                .'WHERE l.external_ref = ? AND l.branch_ref IS NOT NULL', ['fixture:dossier/alpha']);
            self::assertIsArray($row);
            self::assertSame($created['id'], $row['id']);
            self::assertSame(1, (int) $row['version']);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM condor_document_audit '
                .'WHERE record_ref = (SELECT record_ref FROM condor_document_record WHERE id = ?) '
                .'AND action = ?', [$created['id'], 'CREADA']));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM condor_document_branch_link '
                .'WHERE external_ref = ? AND branch_ref IS NOT NULL', ['fixture:dossier/alpha']));
        } finally {
            $db->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private static function json(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        return $body;
    }
}

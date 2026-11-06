<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Reporting\TenantCsvReportService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GestScopeNegativeSqlTest extends WebTestCase
{
    public function testNegativeTenantLegalEntityBranchRoleAndSqlRollback(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $db = $em->getConnection();
        self::assertSame('pdo_mysql', $db->getParams()['driver'] ?? null,
            'La prueba requiere MariaDB real, no SQLite ni dobles de SQL.');

        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Gest A '.$suffix, 'gest-a-'.$suffix);
        $tenantB = new Tenant('Gest B '.$suffix, 'gest-b-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Gest A '.$suffix.' SAS', null, true);
        $legalHidden = new LegalEntity($tenantA, 'Gest Oculta '.$suffix.' SAS', null);
        $legalB = new LegalEntity($tenantB, 'Gest B '.$suffix.' SAS', null, true);
        $branchA = new Branch($tenantA, 'Visible', 'visible-'.$suffix, $legalA, true);
        $branchHidden = new Branch($tenantA, 'Oculta', 'hidden-'.$suffix, $legalHidden);
        $branchB = new Branch($tenantB, 'Externa', 'outside-'.$suffix, $legalB, true);
        $owner = new User('owner-'.$suffix.'@example.test', 'Owner A');
        $delegate = new User('delegate-'.$suffix.'@example.test', 'Delegado A');
        $ownerMembership = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $delegateMembership = new Membership($tenantA, $delegate, 'ADMIN');
        $readRole = new Role($tenantA, 'Solo catálogo '.$suffix, ['catalog.view']);
        $assignment = new BranchRoleAssignment($delegateMembership, $branchA, $readRole);

        $tenantAId = $tenantA->id();
        $tenantBId = $tenantB->id();
        $branchBId = $branchB->id();

        $db->beginTransaction();
        try {
            foreach ([
                $tenantA, $tenantB, $legalA, $legalHidden, $legalB,
                $branchA, $branchHidden, $branchB, $owner, $delegate,
                $ownerMembership, $delegateMembership, $readRole, $assignment,
            ] as $row) {
                $em->persist($row);
            }
            $em->flush();

            // La barrera de dominio también prohíbe una entidad legal de otro tenant.
            try {
                new Branch($tenantA, 'Cruce prohibido', 'cross-'.$suffix, $legalB);
                self::fail('Se aceptó una entidad legal de otro tenant.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('mismo tenant', $exception->getMessage());
            }

            $ownSql = 'SELECT COUNT(*) FROM condor_branch WHERE tenant_id = :t AND id = :b';
            self::assertSame(1, (int) $db->fetchOne($ownSql, [
                't' => $tenantAId, 'b' => $branchA->id(),
            ]));
            self::assertSame(0, (int) $db->fetchOne($ownSql, [
                't' => $tenantAId, 'b' => $branchBId,
            ]));
            self::assertSame(1, (int) $db->fetchOne($ownSql, [
                't' => $tenantBId, 'b' => $branchBId,
            ]));

            // OWNER de A no puede consultar sedes/roles de B.
            $client->loginUser($owner);
            $client->request('GET', '/api/v1/context?branch='.$branchA->id());
            self::assertResponseStatusCodeSame(200);
            $ownerContext = json_decode((string) $client->getResponse()->getContent(),
                true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($tenantAId, $ownerContext['tenant']['id']);
            self::assertCount(2, $ownerContext['legal_entities']);

            $client->request('GET', '/api/v1/branches/'.$branchBId.'/roles');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/v1/context?branch='.$branchBId);
            self::assertResponseStatusCodeSame(403);
            self::assertStringNotContainsString($legalB->legalName(),
                (string) $client->getResponse()->getContent());

            // Delegado: ve solo la sede/entidad asignada y no puede elevar roles.
            $client->loginUser($delegate);
            $client->request('GET', '/api/v1/context?branch='.$branchA->id());
            self::assertResponseStatusCodeSame(200);
            $visibleBody = (string) $client->getResponse()->getContent();
            $visible = json_decode($visibleBody, true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(1, $visible['branches']);
            self::assertCount(1, $visible['legal_entities']);
            self::assertSame($legalA->id(), $visible['legal_entities'][0]['id']);
            self::assertStringNotContainsString($legalHidden->id(), $visibleBody);
            self::assertStringNotContainsString($branchHidden->id(), $visibleBody);
            self::assertStringNotContainsString($tenantBId, $visibleBody);

            $client->request('GET', '/api/v1/context?branch='.$branchHidden->id());
            self::assertResponseStatusCodeSame(403);
            $delegateCrawler = $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $delegateToken = $delegateCrawler->filter('#condor-admin-root')
                ->attr('data-access-token');
            self::assertIsString($delegateToken);
            self::assertNotSame('', $delegateToken);
            $rolesUrl = '/api/v1/branches/'.$branchA->id().'/roles';
            $deniedName = 'Denegado '.$suffix;
            $client->jsonRequest('POST', $rolesUrl, [
                'name' => $deniedName, 'permissions' => ['roles.create'],
            ], ['HTTP_X_CSRF_TOKEN' => $delegateToken]);
            self::assertResponseStatusCodeSame(403);
            self::assertNull($em->getRepository(Role::class)->findOneBy([
                'tenant' => $tenantA, 'name' => $deniedName,
            ]));

            // CSRF y payload inesperado se rechazan antes de persistir.
            $client->loginUser($owner);
            $client->jsonRequest('POST', $rolesUrl, [
                'name' => 'Sin CSRF '.$suffix, 'permissions' => ['catalog.view'],
            ]);
            self::assertResponseStatusCodeSame(403);
            $ownerCrawler = $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $ownerToken = $ownerCrawler->filter('#condor-admin-root')
                ->attr('data-access-token');
            self::assertIsString($ownerToken);
            self::assertNotSame('', $ownerToken);
            $client->jsonRequest('POST', $rolesUrl, [
                'name' => 'Campo extra '.$suffix, 'permissions' => ['catalog.view'],
                'tenant_id' => $tenantBId,
            ], ['HTTP_X_CSRF_TOKEN' => $ownerToken]);
            self::assertResponseStatusCodeSame(422);

            foreach (['Sin CSRF '.$suffix, 'Campo extra '.$suffix, $deniedName] as $name) {
                self::assertNull($em->getRepository(Role::class)->findOneBy([
                    'tenant' => $tenantA, 'name' => $name,
                ]));
            }
            self::assertCount(0, $em->getRepository(AuditEvent::class)->findBy([
                'tenant' => $tenantA,
            ]), 'Operaciones denegadas no deben emitir auditorías de éxito.');

            // CSV real: allowlist y aislamiento incluso con entrada mixta.
            $csv = (new TenantCsvReportService())->build('sales', $tenantAId,
                '2026-11-01', '2026-11-06', [
                    ['tenant_id' => $tenantAId, 'occurred_at' => '2026-11-05',
                        'order_id' => 'OWN-'.$suffix, 'currency' => 'COP',
                        'total_amount_minor' => 5000, 'payment_status' => 'PENDING',
                        'medical_notes' => 'SENSITIVE_NOT_EXPORTABLE'],
                    ['tenant_id' => $tenantBId, 'occurred_at' => '2026-11-05',
                        'order_id' => 'FOREIGN-'.$suffix, 'currency' => 'COP',
                        'total_amount_minor' => 6000, 'payment_status' => 'PENDING'],
                ]);
            self::assertStringContainsString('OWN-'.$suffix, $csv['body']);
            self::assertStringNotContainsString('FOREIGN-'.$suffix, $csv['body']);
            self::assertStringNotContainsString('SENSITIVE_NOT_EXPORTABLE', $csv['body']);
            self::assertStringNotContainsString('medical_notes', $csv['body']);
        } finally {
            // Fixture 100% descartable incluso ante fallo/throw HTTP.
            $db->rollBack();
            $em->clear();
        }

        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_tenant WHERE id = :id', ['id' => $tenantAId],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_tenant WHERE id = :id', ['id' => $tenantBId],
        ));
    }
}

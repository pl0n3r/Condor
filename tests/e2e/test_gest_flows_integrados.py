#!/usr/bin/env python3
"""#1620: HTTP/SQL cross-module smoke with synthetic-only fixtures.

A genuine Symfony KernelBrowser and disposable MariaDB transaction are required.
No mocked successes, owner approvals, production receipts or data exist here.
"""
from __future__ import annotations

from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
CONFIG = ROOT / "phpunit.xml.dist"
REPORT = ROOT / "docs/verificacion/gest-flows-integrados.md"

# The temporary PHPUnit file is intentionally NOT part of another issue's claims.
# Its WebTestCase drives actual controllers, firewall, session, Doctrine and SQL.
PHP_TEST = r'''<?php
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

final class GestSyntheticIntegratedHttpTest extends WebTestCase
{
    public function testSyntheticFlowRealHttpAndMariaDbRollback(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $db = $em->getConnection();
        self::assertSame('pdo_mysql', $db->getParams()['driver'] ?? null);
        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Flujo A '.$suffix, 'flow-a-'.$suffix);
        $tenantB = new Tenant('Flujo B '.$suffix, 'flow-b-'.$suffix);
        $entityA = new LegalEntity($tenantA, 'Flujo A SAS', null, true);
        $entityB = new LegalEntity($tenantB, 'Flujo B SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal', 'flow-a-'.$suffix, $entityA, true);
        $branchB = new Branch($tenantB, 'Externa', 'flow-b-'.$suffix, $entityB, true);
        $owner = new User('flow-owner-'.$suffix.'@example.test', 'Propietario sintético');
        $viewer = new User('flow-reader-'.$suffix.'@example.test', 'Lector sintético');
        $ownerMembership = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $viewerMembership = new Membership($tenantA, $viewer, 'ADMIN');
        $viewRole = new Role($tenantA, 'Lectura flujo '.$suffix, ['settings.view']);
        $assignment = new BranchRoleAssignment($viewerMembership, $branchA, $viewRole);
        $tenantAId = $tenantA->id();
        $tenantBId = $tenantB->id();

        $db->beginTransaction();
        try {
            foreach ([$tenantA, $tenantB, $entityA, $entityB, $branchA, $branchB,
                $owner, $viewer, $ownerMembership, $viewerMembership, $viewRole, $assignment] as $row) {
                $em->persist($row);
            }
            $em->flush();
            $base = '/api/v1/branches/'.$branchA->id();
            $foreign = '/api/v1/branches/'.$branchB->id();
            $compliance = $base.'/compliance/evidence';
            $documents = $base.'/documents';
            $risks = $base.'/risks';
            $quality = $base.'/quality/records';

            $client->request('GET', $compliance);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403, 302]);
            $client->loginUser($owner);
            $page = $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $csrf = $page->filter('#condor-admin-root')->attr('data-access-token');
            self::assertIsString($csrf);
            self::assertNotSame('', $csrf);
            $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

            // Real HTTP with the same authenticated tenant/branch and empty state.
            foreach ([$compliance => 'evidence', $documents => 'documents',
                $risks => 'risks', $quality => 'records'] as $url => $key) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(200);
                self::assertSame([], self::payload($client)[$key]);
            }
            $evidence = ['evidence_ref' => 'fixture:evidence/flujo',
                'obligation_ref' => 'fixture:obligation/flujo', 'type' => 'EVIDENCIA'];
            $client->jsonRequest('POST', $compliance, $evidence);
            self::assertResponseStatusCodeSame(403); // CSRF required.
            $client->jsonRequest('POST', $compliance, [...$evidence, 'tenant_id' => $tenantBId], $headers);
            self::assertResponseStatusCodeSame(422); // No injected foreign tenant.
            $client->jsonRequest('POST', $compliance, $evidence, $headers);
            self::assertResponseStatusCodeSame(201);
            self::assertSame('PENDIENTE', self::payload($client)['evidence']['state']);

            // Connected synthetic reference: compliance evidence -> document -> audit trace.
            $client->jsonRequest('POST', $documents,
                ['reference' => 'fixture:doc/flujo', 'classification' => 'INTERNA'], $headers);
            self::assertResponseStatusCodeSame(201);
            $client->jsonRequest('POST', $documents.'/flujo/evidence',
                ['evidence_ref' => $evidence['evidence_ref']], $headers);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($evidence['evidence_ref'], self::payload($client)['document']['evidence_ref']);
            self::assertFalse(self::payload($client)['can_close']);
            $client->request('POST', $documents.'/flujo/request-review', [], [],
                [...$headers, 'CONTENT_TYPE' => 'application/json'], '{}');
            self::assertResponseStatusCodeSame(200);
            self::assertSame('PENDIENTE_REVISION', self::payload($client)['document']['status']);
            self::assertFalse(self::payload($client)['can_close']);
            $client->request('GET', $documents.'/flujo/trace');
            self::assertResponseStatusCodeSame(200);
            self::assertSame(['CREADO', 'EVIDENCIA_ADJUNTADA', 'REVISION_SOLICITADA'],
                array_column(self::payload($client)['events'], 'action'));

            // Separate M12/M13 real endpoints: partial, NOT a connected business workflow.
            $client->jsonRequest('POST', $risks,
                ['risk_ref' => 'fixture:risk/flujo', 'category' => 'legal',
                 'probability' => 2, 'impact' => 3], $headers);
            self::assertResponseStatusCodeSame(201);
            self::assertSame('PENDIENTE', self::payload($client)['risk']['state']);
            $client->jsonRequest('POST', $risks.'/flujo/state', ['state' => 'APROBADO'], $headers);
            self::assertResponseStatusCodeSame(405); // Wrong verb must not change state.
            $client->jsonRequest('PATCH', $risks.'/flujo/state', ['state' => 'CERRADO'], $headers);
            self::assertResponseStatusCodeSame(422);
            $client->jsonRequest('POST', $quality,
                ['record_ref' => 'fixture:quality-record/flujo',
                 'source_ref' => 'fixture:quality-source/flujo', 'kind' => 'NO_CONFORMIDAD'], $headers);
            self::assertResponseStatusCodeSame(201);
            self::assertSame('PENDIENTE', self::payload($client)['record']['state']);
            $client->jsonRequest('PATCH', $quality.'/flujo/state', ['state' => 'APROBADO'], $headers);
            self::assertResponseStatusCodeSame(422);
            $client->jsonRequest('PATCH', $quality.'/flujo/state', ['state' => 'EN_REVISION'], $headers);
            self::assertResponseStatusCodeSame(200);
            self::assertSame('EN_REVISION', self::payload($client)['record']['state']);

            // Cross-tenant requests are 404, never another company's fixture contents.
            foreach (['/compliance/evidence','/documents','/risks','/quality/records'] as $path) {
                $client->request('GET', $foreign.$path);
                self::assertResponseStatusCodeSame(404);
                self::assertStringNotContainsString('fixture:doc/flujo',
                    (string) $client->getResponse()->getContent());
            }
            $client->loginUser($viewer);
            $client->request('GET', $quality);
            self::assertResponseStatusCodeSame(200);
            $client->jsonRequest('POST', $quality,
                ['record_ref' => 'fixture:quality-record/forbidden',
                 'source_ref' => 'fixture:quality-source/flujo', 'kind' => 'NO_CONFORMIDAD'], $headers);
            self::assertResponseStatusCodeSame(403);
            self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
                'tenant' => $tenantA, 'action' => 'compliance.synthetic.created',
            ]));
            self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
                'tenant' => $tenantA, 'action' => 'documents_synthetic.created',
            ]));
            self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
                'tenant' => $tenantA, 'action' => 'risk.synthetic.created',
            ]));
            self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
                'tenant' => $tenantA, 'action' => 'quality.synthetic.created',
            ]));
        } finally {
            $db->rollBack();
            $em->clear();
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM condor_tenant WHERE id = :id',
            ['id' => $tenantAId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM condor_tenant WHERE id = :id',
            ['id' => $tenantBId]));
    }

    /** @return array<string,mixed> */
    private static function payload(KernelBrowser $client): array
    {
        $value = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }
}
'''


class GestFlowsIntegradosTests(unittest.TestCase):
    def test_authenticated_http_mariadb_transaction(self) -> None:
        """Actual Symfony routes, CSRF, RBAC, sessions, SQL and rollback."""
        self.assertIsNotNone(shutil.which("php"), "PHP CLI requerido")
        self.assertTrue(PHPUNIT.is_file(), "Falta PHPUnit, no simular PASS")
        self.assertTrue(CONFIG.is_file(), "Falta configuración PHPUnit")
        with tempfile.TemporaryDirectory(prefix="condor-gest-http-") as temp:
            php_file = Path(temp) / "GestSyntheticIntegratedHttpTest.php"
            php_file.write_text(PHP_TEST, encoding="utf-8")
            result = subprocess.run(
                [str(PHPUNIT), "--configuration", str(CONFIG),
                 "--filter", "testSyntheticFlowRealHttpAndMariaDbRollback", str(php_file)],
                cwd=ROOT, text=True, capture_output=True, timeout=240, check=False,
            )
        output = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, output[-6000:])
        self.assertRegex(output, r"OK\s*\(\s*1 test\s*,", output[-6000:])

    def test_integral_coverage_not_inferred(self) -> None:
        self.assertTrue(REPORT.is_file(), "Falta reporte de límites")
        report = REPORT.read_text(encoding="utf-8")
        for required in (
            "#1620", "NO_VERIFICABLE", "GEST-FLUJO-4", "#742", "D-059",
            "D-063", "MariaDB", "rollback", "tests/e2e/test_gest_flows_integrados.py",
            "GEST-FLUJO-1", "GEST-FLUJO-2", "GEST-FLUJO-3", "GEST-FLUJO-5",
        ):
            self.assertIn(required, report)
        self.assertNotIn("VALIDADO_EN_PRODUCCION", report)
        self.assertNotIn("GEST_INTEGRAL_CUMPLE", report)
        self.assertLess(len(report), 25000)


if __name__ == "__main__":
    unittest.main()

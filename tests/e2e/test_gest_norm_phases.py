#!/usr/bin/env python3
"""#1638: positive/negative F0-F2 synthetic HTTP+SQL and normative phase evidence.

A real PHP/Symfony KernelBrowser and disposable MariaDB must run in CI.
Missing PHP, PHPUnit, connection or migrations are failures, never skips.
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
PHP_CONFIG = ROOT / "phpunit.xml.dist"
REPORT = ROOT / "docs/verificacion/gest-norm-phases.md"

PHP_TEST = r'''<?php
declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Compliance\NormativeApplicability;
use App\Application\Compliance\NormativeMatrix;
use App\Application\Compliance\NormativeReviewProjection;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GestNormPhasesSyntheticHttpTest extends WebTestCase
{
    /** F0/F1: the actual normative PHP projection refuses unverifiable rules. */
    public function testNormativeProvenanceVersionAndReviewFailClosed(): void
    {
        $reference = '2026-10-24';
        $context = [
            'tenant_ref' => 'empresa_demo', 'sector' => 'comercio',
            'ciiu' => '4711', 'municipio' => 'pereira', 'riesgo' => 'ii',
            'tamano_empresa' => 'micro', 'regimen' => 'simple',
        ];
        $rule = [
            'tenant_ref' => 'empresa_demo', 'kind' => 'OBLIGATORIO',
            'approved' => true, 'reviewer_ref' => 'revisor_ficticio',
            'source' => [
                'kind' => 'official', 'issuer' => 'emisor_ficticio',
                'article' => 'articulo_01', 'version' => 'v1',
                'official_url' => 'https://normas.example.invalid/fixture',
            ],
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31',
            'verified_on' => '2026-02-01',
            'conditions' => ['sector' => 'comercio', 'municipio' => 'pereira'],
        ];

        $valid = NormativeApplicability::evaluate($rule, $context, $reference);
        self::assertSame('APLICA', $valid['applicability']);
        self::assertSame('OBLIGATORIO', $valid['kind']);
        self::assertSame('v1', $valid['source']['version']);
        $matrix = NormativeMatrix::evaluate([$rule], $context, $reference);
        self::assertCount(1, $matrix['rows']);
        self::assertSame('APLICA', $matrix['rows'][0]['applicability']);

        $other = [...$context, 'municipio' => 'medellin'];
        self::assertSame('NO_APLICA',
            NormativeApplicability::evaluate($rule, $other, $reference)['applicability']);
        $unknown = [...$context, 'municipio' => 'UNKNOWN'];
        self::assertSame('REQUIERE_REVISION',
            NormativeApplicability::evaluate($rule, $unknown, $reference)['kind']);
        foreach ([
            [...$rule, 'approved' => false],
            [...$rule, 'reviewer_ref' => 'UNKNOWN'],
            [...$rule, 'effective_until' => '2026-04-01'],
            [...$rule, 'verified_on' => '2027-01-01'],
            [...$rule, 'source' => [...$rule['source'], 'version' => 'UNKNOWN']],
            [...$rule, 'source' => [...$rule['source'], 'kind' => 'unverified']],
        ] as $untrusted) {
            self::assertSame('REQUIERE_REVISION',
                NormativeApplicability::evaluate($untrusted, $context, $reference)['kind']);
        }
        self::assertSame([], NormativeMatrix::evaluate(
            [[...$rule, 'tenant_ref' => 'tenant_externo']], $context, $reference
        )['rows']);

        $unreviewed = NormativeReviewProjection::evaluate([$rule], $context, $reference, []);
        self::assertSame('PENDIENTE', $unreviewed['rows'][0]['state']);
        $review = [
            'tenant_ref' => 'empresa_demo', 'municipio' => 'pereira',
            'row_index' => 0, 'state' => 'EN_REVISION',
            'evidence_ref' => 'fixture:evidence/norma', 'reviewer_ref' => 'otro_revisor',
            'reviewed_on' => '2026-05-02',
        ];
        $pending = NormativeReviewProjection::evaluate([$rule], $context, $reference, [$review]);
        self::assertSame('EN_REVISION', $pending['rows'][0]['state']);
        self::assertSame('fixture:evidence/norma', $pending['rows'][0]['evidence_ref']);
        self::assertSame('REQUIERE_REVISION',
            NormativeReviewProjection::evaluate(
                [$rule], $context, $reference, [[...$review, 'state' => 'CUMPLE']]
            )['rows'][0]['state']);
        self::assertSame('REQUIERE_REVISION',
            NormativeReviewProjection::evaluate(
                [$rule], $context, $reference, [[...$review, 'reviewed_on' => '2025-12-01']]
            )['rows'][0]['state']);
        self::assertSame('REQUIERE_REVISION',
            NormativeReviewProjection::evaluate(
                [$rule], $context, $reference, [$review, $review]
            )['rows'][0]['state']);
    }

    /** F0/F1/F2: real Symfony firewall/session, SQL transaction, and HTTP API. */
    public function testNormativeHttpMariaDbOwnerIsolationAndNoAutoApproval(): void
    {
        putenv('CONDOR_LEGAL_SYNTHETIC_API=1');
        putenv('CONDOR_PRODUCTION_STAGE=construccion');
        try {
            $browser = static::createClient();
            $browser->disableReboot();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $db = $em->getConnection();
            self::assertSame('pdo_mysql', $db->getParams()['driver'] ?? null);

            $suffix = bin2hex(random_bytes(5));
            $tenant = new Tenant('Norma ficticia A', 'gest-norma-a-'.$suffix);
            $foreign = new Tenant('Norma ficticia B', 'gest-norma-b-'.$suffix);
            $branch = new Branch($tenant, 'Sede A', 'norma-a-'.$suffix, null, true);
            $other = new Branch($foreign, 'Sede B', 'norma-b-'.$suffix, null, true);
            $owner = new User('norm-owner-'.$suffix.'@example.test', 'Owner ficticio');
            $delegate = new User('norm-reader-'.$suffix.'@example.test', 'Lector ficticio');
            $membership = new Membership($tenant, $delegate, 'ADMIN');
            $role = new Role($tenant, 'Lectura limitada '.$suffix, ['roles.view']);
            $tenantId = $tenant->id();
            $foreignId = $foreign->id();
            $db->beginTransaction();
            try {
                foreach ([
                    $tenant, $foreign, $branch, $other, $owner, $delegate,
                    new Membership($tenant, $owner, Membership::ROLE_OWNER),
                    $membership, $role,
                    new BranchRoleAssignment($membership, $branch, $role),
                ] as $row) {
                    $em->persist($row);
                }
                $em->flush();
                $url = '/api/v1/branches/'.$branch->id().'/legal-compliance/';

                $browser->request('GET', $url.'perfil');
                self::assertContains($browser->getResponse()->getStatusCode(), [401, 403, 302]);
                $browser->loginUser($owner);
                $admin = $browser->request('GET', '/admin');
                self::assertResponseIsSuccessful();
                $csrf = $admin->filter('#condor-admin-root')->attr('data-access-token');
                self::assertIsString($csrf);
                self::assertNotSame('', $csrf);
                $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

                foreach (['perfil', 'catalogo', 'matriz', 'calendario', 'evaluaciones', 'auditorias'] as $section) {
                    $browser->request('GET', $url.$section);
                    self::assertResponseStatusCodeSame(200);
                    $empty = self::payload($browser);
                    self::assertSame([], $empty['rows']);
                    self::assertFalse($empty['automatically_approved']);
                    self::assertFalse($empty['automatically_closed']);
                }
                $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'demo']);
                self::assertResponseStatusCodeSame(403);
                $browser->jsonRequest('POST', $url.'fixtures',
                    ['scenario' => 'demo', 'customer_email' => 'p@example.test'], $headers);
                self::assertResponseStatusCodeSame(422);
                $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'real'], $headers);
                self::assertResponseStatusCodeSame(422);
                $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'demo'], $headers);
                self::assertResponseStatusCodeSame(200);

                $views = [];
                foreach (['perfil', 'catalogo', 'matriz', 'calendario', 'evaluaciones', 'auditorias'] as $section) {
                    $browser->request('GET', $url.$section);
                    self::assertResponseStatusCodeSame(200);
                    $body = self::payload($browser);
                    self::assertNotEmpty($body['rows'], $section);
                    self::assertTrue($body['synthetic_only']);
                    self::assertTrue($body['requires_professional_review']);
                    self::assertFalse($body['automatically_approved']);
                    self::assertFalse($body['automatically_closed']);
                    self::assertStringContainsString('no-store',
                        (string) $browser->getResponse()->headers->get('Cache-Control'));
                    $views[$section] = $body['rows'];
                }
                self::assertSame('APLICA', $views['perfil'][0]['evaluacion']['applicability']);
                self::assertSame('v1', $views['catalogo'][0]['source']['version']);
                self::assertSame('OBLIGATORIO', $views['matriz'][0]['kind']);
                self::assertSame('PROHIBIDO', $views['matriz'][1]['kind']);
                self::assertSame('EN_REVISION', $views['auditorias'][0]['state']);
                self::assertNotSame('CUMPLE', $views['evaluaciones'][0]['state'] ?? null);

                $browser->request('GET', '/api/v1/branches/'.$other->id().'/legal-compliance/matriz');
                self::assertResponseStatusCodeSame(404);
                self::assertStringNotContainsString('emisor-ficticio',
                    (string) $browser->getResponse()->getContent());
                $browser->jsonRequest('PATCH', $url.'fixtures', ['scenario' => 'vacio']);
                self::assertResponseStatusCodeSame(403);
                $browser->jsonRequest('PATCH', $url.'fixtures', ['scenario' => 'vacio'], $headers);
                self::assertResponseStatusCodeSame(200);
                $browser->request('GET', $url.'catalogo');
                self::assertSame([], self::payload($browser)['rows']);

                $browser->loginUser($delegate);
                $browser->request('GET', $url.'matriz');
                self::assertResponseStatusCodeSame(403);
                $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'demo'], $headers);
                self::assertResponseStatusCodeSame(403);
                putenv('CONDOR_LEGAL_SYNTHETIC_API=0');
                $browser->loginUser($owner);
                $browser->request('GET', $url.'perfil');
                self::assertResponseStatusCodeSame(404);
            } finally {
                $db->rollBack();
                $em->clear();
            }
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_tenant WHERE id = :id', ['id' => $tenantId]
            ));
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_tenant WHERE id = :id', ['id' => $foreignId]
            ));
        } finally {
            putenv('CONDOR_LEGAL_SYNTHETIC_API');
            putenv('CONDOR_PRODUCTION_STAGE');
        }
    }

    /** @return array<string,mixed> */
    private static function payload(\Symfony\Bundle\FrameworkBundle\KernelBrowser $browser): array
    {
        $payload = json_decode((string) $browser->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }
}
'''


class GestNormPhasesE2ETests(unittest.TestCase):
    def test_normative_http_mariadb_and_versioned_rules(self) -> None:
        """Two genuine PHPUnit tests: pure normative rules plus HTTP/MariaDB."""
        self.assertIsNotNone(shutil.which("php"), "Falta PHP CLI")
        self.assertTrue(PHPUNIT.is_file(), "PHPUnit ausente: no simular PASS")
        self.assertTrue(PHP_CONFIG.is_file(), "Configuración PHPUnit ausente")
        with tempfile.TemporaryDirectory(prefix="condor-gest-norm-") as workdir:
            php_file = Path(workdir) / "GestNormPhasesSyntheticHttpTest.php"
            php_file.write_text(PHP_TEST, encoding="utf-8")
            result = subprocess.run(
                [str(PHPUNIT), "--configuration", str(PHP_CONFIG),
                 "--filter", "testNormative", str(php_file)],
                cwd=ROOT, text=True, capture_output=True, timeout=240, check=False,
            )
        output = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, output[-7500:])
        self.assertRegex(output, r"OK\s*\(\s*2 tests?\s*,", output[-7500:])

    def test_18_rows_are_explicit_and_never_falsely_promoted(self) -> None:
        self.assertTrue(REPORT.is_file(), "Falta reporte por fila #1638")
        report = REPORT.read_text(encoding="utf-8")
        ids = re.findall(
            r"^\| (GEST-(?:NORM-(?:01|02|03|07)|F[0-6]|AC-0[1-7])) \| NO_VERIFICABLE \|",
            report, flags=re.MULTILINE,
        )
        required = {
            "GEST-NORM-01", "GEST-NORM-02", "GEST-NORM-03", "GEST-NORM-07",
            *(f"GEST-F{number}" for number in range(7)),
            *(f"GEST-AC-0{number}" for number in range(1, 8)),
        }
        self.assertEqual(18, len(ids), "Faltan filas o existen duplicados")
        self.assertEqual(required, set(ids))
        for text in (
            "#1638", "#1620", "#742", "D-059", "D-063",
            "MariaDB", "rollback", "REQUIERE_REVISION",
            "tests/e2e/test_gest_norm_phases.py",
            "tests/contract/test_gest_norm_phases_runtime.py",
            "NO_VERIFICABLE",
        ):
            self.assertIn(text, report)
        self.assertNotIn("GEST_INTEGRAL_CUMPLE", report)
        self.assertLess(len(report), 28000)


if __name__ == "__main__":
    unittest.main()

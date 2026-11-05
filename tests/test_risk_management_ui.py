#!/usr/bin/env python3
"""Condor #1583 · UI HTTP y controles reales de RiskController (M12 S04)."""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "frontend/admin/RiskManagement.tsx"
CONTROLLER = ROOT / "src/Http/Controller/RiskController.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"

HTTP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RiskManagementHttpAcceptanceTest extends WebTestCase
{
    public function testHttpPermissionsAndTypedSyntheticScenarios(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $id = strtolower(bin2hex(random_bytes(5)));
        $a = new Tenant('Riesgo A '.$id, 'risk-a-'.$id);
        $b = new Tenant('Riesgo B '.$id, 'risk-b-'.$id);
        $legalA = new LegalEntity($a, 'SAS A', null, true);
        $legalB = new LegalEntity($b, 'SAS B', null, true);
        $branchA = new Branch($a, 'Sede A', 'sede-a-'.$id, $legalA, true);
        $branchB = new Branch($b, 'Sede B', 'sede-b-'.$id, $legalB, true);
        $ownerA = new User('risk-owner-a-'.$id.'@example.test', 'Owner A');
        $ownerB = new User('risk-owner-b-'.$id.'@example.test', 'Owner B');
        $reader = new User('risk-reader-'.$id.'@example.test', 'Reader A');
        $member = new Membership($a, $reader, 'STAFF');
        $role = new Role($a, 'Leer riesgos '.$id, ['settings.view']);
        $assignment = new BranchRoleAssignment($member, $branchA, $role);
        foreach ([$a, $b, $legalA, $legalB, $branchA, $branchB,
            $ownerA, $ownerB, $reader, $member, $role, $assignment,
            new Membership($a, $ownerA, Membership::ROLE_OWNER),
            new Membership($b, $ownerB, Membership::ROLE_OWNER)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $urlA = '/api/v1/branches/'.$branchA->id().'/risks';
        $urlB = '/api/v1/branches/'.$branchB->id().'/risks';
        $technical = ['risk_ref' => 'fixture:risk/tech-a',
            'category' => 'tecnologico', 'probability' => 2, 'impact' => 3];
        $reputational = ['risk_ref' => 'fixture:risk/reputation-a',
            'category' => 'reputacional', 'probability' => 3, 'impact' => 4,
            'scenario_ref' => 'fixture:risk-scenario/brand-a'];
        $client->loginUser($ownerA);
        $html = $client->request('GET', '/admin');
        $csrf = $html->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode(
            (string) $client->getResponse()->getContent(), true)['risks']);

        $client->jsonRequest('POST', $urlA, $technical);
        self::assertResponseStatusCodeSame(403); // CSRF no ausente
        $client->jsonRequest('POST', $urlA, $technical, $headers);
        self::assertResponseStatusCodeSame(201);
        $client->jsonRequest('POST', $urlA, array_diff_key(
            $reputational, ['scenario_ref' => true]), $headers);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', $urlA, $reputational, $headers);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(2, $list['risks']);
        self::assertSame('reputacional', $list['risks'][1]['category']);
        self::assertSame('fixture:risk-scenario/brand-a',
            $list['risks'][1]['scenario_ref']);

        $stateUrl = $urlA.'/reputation-a/state';
        $client->jsonRequest('PATCH', $stateUrl, ['state' => 'EN_REVISION'], $headers);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('PATCH', $stateUrl, ['state' => 'APROBADO'], $headers);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('PATCH', $stateUrl, ['state' => 'CERRADO'], $headers);
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', $urlB);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $urlB, $technical, $headers);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($ownerB);
        $client->request('GET', $urlB);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode(
            (string) $client->getResponse()->getContent(), true)['risks']);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($reader);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('POST', $urlA, $technical, $headers);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PATCH', $stateUrl,
            ['state' => 'REQUIERE_REVISION'], $headers);
        self::assertResponseStatusCodeSame(403);

        $client->restart();
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(401);
    }
}
"""


class RiskManagementUiTests(unittest.TestCase):
    def test_frontend_uses_real_http_with_fail_closed_states(self) -> None:
        src = COMPONENT.read_text(encoding="utf-8")
        http = src.split("// M12 S04: vista HTTP de pruebas.", 1)[1]
        for required in (
            "RiskHttpWorkspace", "XMLHttpRequest", "xhr.withCredentials = true",
            "xhr.onerror", "xhr.ontimeout", "xhr.onabort", "request.abort()",
            "useLayoutEffect", "loading", "denied", "error", "ready",
            "401, 403, 404", "response.status === 201",
            "response.status === 200", "settings.view", "settings.create",
            "settings.update", "X-CSRF-Token", "data-access-token",
            "fixture:risk-scenario/", "reputacional", "tecnologico",
            "Number.isInteger", "isHttpRisk", "role=\"alert\"",
            "role=\"status\"", "Solo construcción",
        ):
            self.assertIn(required, http, required)
        self.assertRegex(http, r"category === 'reputacional'[\s\S]{0,110}scenario_ref")
        self.assertRegex(http, r"if \(\[401, 403, 404\]\.includes\(xhr\.status\)\)")
        self.assertNotIn("state: 'APROBADO'", http)
        self.assertNotIn("state: 'CERRADO'", http)
        self.assertNotIn("window.location", http)
        self.assertNotIn("localStorage", http)
        self.assertNotIn("sessionStorage", http)
        self.assertIn("if (!import.meta.env.DEV) return null;", http)
        self.assertIn("<RiskHttpWorkspace {...props} />", http)

    def test_controller_http_401_403_404_and_fixtures(self) -> None:
        """WebTestCase real; nunca hacer pasar un contrato estático por HTTP."""
        self.assertTrue(CONTROLLER.is_file())
        self.assertTrue(PHPUNIT.is_file(), "Se necesita PHPUnit real")
        self.assertEqual("test", os.environ.get("APP_ENV"), "Solo entorno de test")
        parsed = urlsplit(os.environ.get("DATABASE_URL", ""))
        db = parsed.path.strip("/")
        self.assertIn(parsed.scheme, ("mysql", "mariadb"))
        self.assertIn(parsed.hostname, ("localhost", "127.0.0.1", "::1"))
        self.assertTrue(db.startswith("condor_") and
                        ("test" in db or "_ci_" in db), "DB descartable obligatoria")
        php = shutil.which("php")
        self.assertIsNotNone(php)
        with tempfile.TemporaryDirectory(prefix="condor-risk-ui-http-") as directory:
            scenario = Path(directory) / "RiskManagementHttpAcceptanceTest.php"
            scenario.write_text(HTTP_SCENARIO, encoding="utf-8")
            lint = subprocess.run([php, "-l", str(scenario)], cwd=ROOT,
                                  capture_output=True, text=True, timeout=25)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run([str(PHPUNIT), str(scenario)], cwd=ROOT,
                                    capture_output=True, text=True, timeout=180)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertRegex(result.stdout + result.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

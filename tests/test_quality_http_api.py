#!/usr/bin/env python3
"""Condor #1054 M13: synthetic quality API regressions, with real HTTP when Symfony is installed."""

from __future__ import annotations

import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Quality/QualityApiService.php"
CONTROLLER = ROOT / "src/Http/Controller/QualityController.php"
SUPPORT = ROOT / "src/Http/Controller/BranchApiSupport.php"

HTTP_SMOKE = r'''<?php
declare(strict_types=1);

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

final class QualitySyntheticHttpSmokeTest extends WebTestCase
{
    public function testTenantRolesAndExplicitStateViaHttp(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Calidad A '.$suffix, 'qa-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Calidad A SAS '.$suffix, null, true);
        $branchA = new Branch($tenantA, 'Sede A', 'qa-a-'.$suffix, $legalA, true);
        $tenantB = new Tenant('Calidad B '.$suffix, 'qa-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Calidad B SAS '.$suffix, null, true);
        $branchB = new Branch($tenantB, 'Sede B', 'qa-b-'.$suffix, $legalB, true);
        $ownerA = new User('quality-a-'.$suffix.'@example.test', 'Owner A');
        $ownerB = new User('quality-b-'.$suffix.'@example.test', 'Owner B');
        $viewer = new User('quality-reader-'.$suffix.'@example.test', 'Reader');
        $memberA = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);
        $memberB = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);
        $memberViewer = new Membership($tenantA, $viewer, 'ADMIN');
        $role = new Role($tenantA, 'Vista calidad '.$suffix, ['settings.view']);
        $assignment = new BranchRoleAssignment($memberViewer, $branchA, $role);
        foreach ([$tenantA, $legalA, $branchA, $tenantB, $legalB, $branchB,
            $ownerA, $ownerB, $viewer, $memberA, $memberB, $memberViewer,
            $role, $assignment] as $record) {
            $em->persist($record);
        }
        $em->flush();
        $baseA = '/api/v1/branches/'.$branchA->id().'/quality/records';
        $baseB = '/api/v1/branches/'.$branchB->id().'/quality/records';

        // Anonymous caller gets no quality data; a valid owner may create only fixture refs.
        $client->request('GET', $baseA);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403, 302]);
        $client->loginUser($ownerA);
        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];
        $ref = 'fixture:quality-record/sample';
        $sourceA = 'fixture:quality-source/owned';
        $client->jsonRequest('POST', $baseA, [
            'record_ref' => $ref, 'source_ref' => $sourceA, 'kind' => 'NO_CONFORMIDAD',
        ], $headers);
        self::assertResponseStatusCodeSame(201);
        $row = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($row['synthetic_only']);
        self::assertSame('PENDIENTE', $row['record']['state']);
        self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
            'tenant' => $tenantA, 'action' => 'quality.synthetic.created',
        ]));

        // Neither external branch access nor a request without CSRF is a write.
        $client->request('GET', $baseB);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('PATCH', $baseA.'/sample/state', ['state' => 'EN_REVISION']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PATCH', $baseA.'/sample/state', ['state' => 'CERRADO'], $headers);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', $baseA.'/sample');
        self::assertResponseStatusCodeSame(200);
        $unchanged = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PENDIENTE', $unchanged['record']['state']);
        $client->jsonRequest('PATCH', $baseA.'/sample/state', ['state' => 'EN_REVISION'], $headers);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
            'tenant' => $tenantA, 'action' => 'quality.synthetic.state_changed',
        ]));

        // A delegated reader can inspect but never mutate.
        $client->loginUser($viewer);
        $client->request('GET', $baseA);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('POST', $baseA, [
            'record_ref' => 'fixture:quality-record/blocked',
            'source_ref' => 'fixture:quality-source/owned', 'kind' => 'NO_CONFORMIDAD',
        ], $headers);
        self::assertResponseStatusCodeSame(403);

        // Two tenants may use the same synthetic ref without observing each other's data.
        $client->loginUser($ownerB);
        $page = $client->request('GET', '/admin');
        $csrfB = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrfB);
        $client->jsonRequest('POST', $baseB, [
            'record_ref' => $ref, 'source_ref' => 'fixture:quality-source/other',
            'kind' => 'AUDITORIA_INTERNA',
        ], ['HTTP_X_CSRF_TOKEN' => $csrfB]);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', $baseB);
        self::assertResponseStatusCodeSame(200);
        $rowsB = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('AUDITORIA_INTERNA', $rowsB['records'][0]['kind']);
        $client->request('GET', $baseA.'/sample');
        self::assertResponseStatusCodeSame(404);
        $client->loginUser($ownerA);
        $client->request('GET', $baseA.'/sample');
        self::assertResponseStatusCodeSame(200);
        $rowsA = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('NO_CONFORMIDAD', $rowsA['record']['kind']);
        self::assertSame('EN_REVISION', $rowsA['record']['state']);
    }
}
'''


def php_script(script: str) -> subprocess.CompletedProcess[str]:
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("PHP CLI requerido por la aceptación de Calidad M13")
    return subprocess.run(
        [executable, "-r", f"require {str(SERVICE)!r};\n" + script],
        text=True, capture_output=True, check=False,
    )


class ModuloTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self) -> None:
        script = r'''
use App\Application\Quality\QualityApiService as Api;
$a = hash('sha256', 'tenant-a|branch');
$b = hash('sha256', 'tenant-b|branch');
$payload = ['record_ref' => 'fixture:quality-record/shared', 'source_ref' => 'fixture:quality-source/alpha', 'kind' => 'NO_CONFORMIDAD'];
$first = Api::createRecord([], $a, $payload);
if (Api::listRecords($first['store'], $b) !== []) { exit(2); }
$payload['source_ref'] = 'fixture:quality-source/bravo';
$payload['kind'] = 'AUDITORIA_INTERNA';
$second = Api::createRecord($first['store'], $b, $payload);
if (Api::listRecords($second['store'], $a)[0]['kind'] !== 'NO_CONFORMIDAD') { exit(3); }
if (Api::listRecords($second['store'], $b)[0]['source_ref'] !== 'fixture:quality-source/bravo') { exit(4); }
if (Api::viewRecord($second['store'], $b, 'fixture:quality-record/nonexistent') !== null) { exit(5); }
echo 'PASS';
'''
        result = php_script(script)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual('PASS', result.stdout)
        controller = CONTROLLER.read_text(encoding="utf-8")
        support = SUPPORT.read_text(encoding="utf-8")
        self.assertIn("$tenant->id().'|'.$branch->id()", controller)
        self.assertIn("'tenant' => $tenant", support)
        self.assertIn("'id' => $branchId", support)

    def test_permisos_y_auditoria(self) -> None:
        code = CONTROLLER.read_text(encoding="utf-8")
        support = SUPPORT.read_text(encoding="utf-8")
        for route in ("quality/records", "quality/records/{slug}", "quality/records/{slug}/state"):
            self.assertIn(route, code)
        for permission in ("settings.view", "settings.create", "settings.update"):
            self.assertIn(f"'{permission}'", code)
        self.assertIn("$this->authorizedBranchScope($branchId, $permission)", code)
        self.assertIn("$this->authorization->require(", support)
        self.assertIn("$this->requireCsrf($request)", code)
        self.assertIn("$this->audit($tenant, $actor, 'quality.synthetic.'", code)
        self.assertIn("$this->entityManager->flush()", code)
        self.assertIn("$environment !== 'test'", code)
        self.assertIn("getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'", code)
        self.assertNotIn("$request->request->get('tenant')", code)
        self.assertNotIn("'publish'", code)
        for path in (SERVICE, CONTROLLER):
            checked = subprocess.run([shutil.which('php') or 'php', '-l', str(path)], text=True, capture_output=True, check=False)
            self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)
        # When the Symfony test installation exists, execute actual authenticated HTTP.
        runner = ROOT / 'vendor/bin/simple-phpunit'
        if runner.exists():
            with tempfile.TemporaryDirectory(prefix='condor-quality-http-') as tmp:
                script = Path(tmp) / 'QualitySyntheticHttpSmokeTest.php'
                script.write_text(HTTP_SMOKE, encoding='utf-8')
                result = subprocess.run([str(runner), str(script)], cwd=ROOT, text=True, capture_output=True, check=False)
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_sin_datos_reales(self) -> None:
        script = r'''
use App\Application\Quality\QualityApiService as Api;
$scope = hash('sha256', 'sample');
$base = ['record_ref'=>'fixture:quality-record/one', 'source_ref'=>'fixture:quality-source/one', 'kind'=>'NO_CONFORMIDAD'];
foreach ([
    array_merge($base, ['name'=>'Persona real']),
    array_merge($base, ['record_ref'=>'cedula:12345678']),
    array_merge($base, ['source_ref'=>'person@example.com']),
    array_merge($base, ['record_ref'=>'fixture:quality-record/123456789']),
] as $bad) {
    try { Api::createRecord([], $scope, $bad); exit(2); }
    catch (DomainException $error) { /* expected fail closed */ }
}
if (Api::listRecords([], $scope) !== []) { exit(3); }
echo 'PASS';
'''
        result = php_script(script)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual('PASS', result.stdout)
        text = CONTROLLER.read_text(encoding='utf-8') + SERVICE.read_text(encoding='utf-8')
        self.assertNotIn('HttpClient', text)
        self.assertNotIn('Mailer', text)

    def test_estados_explicitos(self) -> None:
        script = r'''
use App\Application\Quality\QualityApiService as Api;
$scope = hash('sha256', 'test-state');
$p = ['record_ref'=>'fixture:quality-record/one', 'source_ref'=>'fixture:quality-source/one', 'kind'=>'ACCION_CORRECTIVA'];
$created = Api::createRecord([], $scope, $p);
if ($created['record']['state'] !== 'PENDIENTE') { exit(2); }
foreach (['CERRADO', 'APROBADO', 'REALIZADO', '', 'AUTO'] as $bad) {
    try { Api::changeState($created['store'], $scope, 'fixture:quality-record/one', $bad); exit(3); }
    catch (DomainException $error) { /* no automatic close */ }
}
$changed = Api::changeState($created['store'], $scope, 'fixture:quality-record/one', 'EN_REVISION');
if ($changed['record']['state'] !== 'EN_REVISION') { exit(4); }
if (Api::viewRecord($created['store'], $scope, 'fixture:quality-record/one')['state'] !== 'PENDIENTE') { exit(5); }
echo 'PASS';
'''
        result = php_script(script)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual('PASS', result.stdout)
        code = SERVICE.read_text(encoding='utf-8')
        self.assertIn("['EN_REVISION', 'REQUIERE_REVISION']", code)
        self.assertNotIn("'CERRADO'", code)


if __name__ == '__main__':
    unittest.main()

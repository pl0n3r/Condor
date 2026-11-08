#!/usr/bin/env python3
"""Procesos #1664: isolation, immutable audit, deny-by-default and D-059."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "src"
API = PHP / "Http/Controller/OperationsProcessController.php"
SERVICE = PHP / "Application/Workflow/OperationalProcessService.php"
MIGRATION = ROOT / "migrations/Version20261106137000.php"
TENANT_A = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
TENANT_B = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
COMPANY_A = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
COMPANY_B = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
ACTOR = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"

DB_SETUP = r"""
$db = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_sqlite', 'memory' => true,
]);
$db->executeStatement(
    'CREATE TABLE condor_operations_process_real_record (id VARCHAR(26) PRIMARY KEY, '
    .'tenant_id VARCHAR(26), company_id VARCHAR(26), record_ref VARCHAR(64), '
    .'kind VARCHAR(20), state VARCHAR(20), version INT, '
    .'previous_id VARCHAR(26), actor_id VARCHAR(26), occurred_at DATETIME, '
    .'UNIQUE(tenant_id, company_id, record_ref, version))'
);
$records = new \App\Application\Workflow\OperationalProcessService($db);
"""


class OperationalProcessesPersistenceTests(unittest.TestCase):
    def php_case(self, script: str):
        loader = ROOT / "vendor/autoload.php"
        self.assertTrue(loader.exists(), "Composer autoload required")
        command = [shutil.which("php") or "php", "-r",
                   "require $argv[1]; " + DB_SETUP + script, str(loader)]
        proc = subprocess.run(command, cwd=str(ROOT), text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              timeout=40)
        if proc.returncode:
            self.fail(f"PHP failed ({proc.returncode}): {proc.stderr}")
        return json.loads(proc.stdout)

    def test_tenant_and_company_scope_with_append_only_audit(self):
        result = self.php_case(f"""
$t = '{TENANT_A}'; $other = '{TENANT_B}';
$c = '{COMPANY_A}'; $second = '{COMPANY_B}';
$a = '{ACTOR}'; $input = ['record_ref' => 'riesgo', 'kind' => 'PROCESO'];
$first = $records->add($t, $c, $a, $input);
$records->add($other, $c, $a, $input);
$records->add($t, $second, $a, $input);
$change = $records->transition($t, $c, $a, 'riesgo', 'EN_REVISION', 1);
echo json_encode([
    count($records->all($t, $c)),
    count($records->all($other, $c)),
    count($records->all($t, $second)),
    count($records->all($other, $second)),
    $records->one($t, $c, 'riesgo')['version'],
    $change['previous_id'] === $first['id'],
    $change['actor_id'] === $a,
    (int) $db->fetchOne('SELECT COUNT(*) FROM condor_operations_process_real_record '
        .'WHERE tenant_id=? AND company_id=?', [$t, $c]),
]);
""")
        self.assertEqual([1, 1, 1, 0, 2, True, True, 2], result)

    def test_keyset_pagination_reaches_records_beyond_first_hundred(self):
        self.assertEqual(self.php_case(f"""
$t='{TENANT_A}'; $c='{COMPANY_A}'; $a='{ACTOR}';
for ($i = 0; $i < 101; $i++) {{
    $records->add($t, $c, $a, [
        'record_ref' => sprintf('acta_%03d', $i), 'kind' => 'PROCESO',
    ]);
}}
$first = $records->page($t, $c);
$second = $records->page($t, $c, $first['next_cursor']);
$isolated = $records->page('{TENANT_B}', $c);
$badCursor = false;
try {{ $records->page($t, $c, 'persona@example.com'); }}
catch (\\DomainException $error) {{ $badCursor = true; }}
echo json_encode([
    count($first['records']), $first['records'][0]['record_ref'],
    $first['next_cursor'], count($second['records']),
    $second['records'][0]['record_ref'], $second['next_cursor'],
    count($isolated['records']), $badCursor,
]);
"""), [100, 'acta_000', 'acta_099', 1, 'acta_100', None, 0, True])

    def test_noncanonical_ulid_is_rejected_before_persistence(self):
        self.assertEqual(self.php_case(f"""
$t='{TENANT_A}'; $c='{COMPANY_A}'; $a='{ACTOR}';
$invalid = '8'.substr($t, 1);
try {{
    $records->add($invalid, $c, $a, ['record_ref'=>'acta','kind'=>'PROCESO']);
    echo json_encode(false);
}} catch (\\DomainException $error) {{
    echo json_encode(true);
}}
"""), True)

    def test_never_accept_clinical_data_duplicate_or_auto_approval(self):
        self.assertEqual(self.php_case(f"""
$t='{TENANT_A}'; $c='{COMPANY_A}'; $a='{ACTOR}';
$input=['record_ref'=>'inspeccion','kind'=>'PROCEDIMIENTO'];
$records->add($t,$c,$a,$input);
$attempts=[
    fn()=> $records->add($t,$c,$a,$input),
    fn()=> $records->add($t,$c,$a,[...$input,'record_ref'=>'persona@example.com']),
    fn()=> $records->add($t,$c,$a,[...$input,'kind'=>'DIAGNOSTICO']),
    fn()=> $records->add($t,$c,$a,[...$input,'historia_clinica'=>'x']),
    fn()=> $records->transition($t,$c,$a,'inspeccion','APROBADO',1),
    fn()=> $records->transition($t,$c,$a,'inspeccion','EN_REVISION',12),
    fn()=> $records->transition($t,$c,$a,'ausente','EN_REVISION',1),
];
$rejections=[];
foreach($attempts as $attempt) {{
    try {{ $attempt(); $rejections[]=false; }}
    catch(\\DomainException $e) {{ $rejections[]=true; }}
}}
echo json_encode($rejections);
"""), [True] * 7)

    def test_authenticated_http_enforces_owner_tenant_and_csrf(self):
        """Ejecuta HTTP real con KernelBrowser y una base SQLite efímera."""
        script = r'''
putenv('APP_ENV=test');
putenv('APP_DEBUG=0');
putenv('CONDOR_EPHEMERAL_CACHE=1');
putenv('DATABASE_URL=sqlite:///:memory:');
putenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED=true');
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
$tool = new \Doctrine\ORM\Tools\SchemaTool($em);
$fixtureEntities = [
    \App\Domain\Organization\Entity\Tenant::class,
    \App\Domain\Organization\Entity\LegalEntity::class,
    \App\Domain\Organization\Entity\Branch::class,
    \App\Domain\Identity\Entity\User::class,
    \App\Domain\Identity\Entity\Membership::class,
    \App\Domain\Identity\Entity\Role::class,
    \App\Domain\Identity\Entity\BranchRoleAssignment::class,
];
$fixtureMetadata = array_map(
    fn (string $className) => $em->getClassMetadata($className),
    $fixtureEntities,
);
$tool->createSchema($fixtureMetadata);
$db = $em->getConnection();
$db->executeStatement(
    'CREATE TABLE condor_operations_process_real_record (id VARCHAR(26) PRIMARY KEY, '
    .'tenant_id VARCHAR(26), company_id VARCHAR(26), record_ref VARCHAR(64), '
    .'kind VARCHAR(20), state VARCHAR(20), version INT, '
    .'previous_id VARCHAR(26), actor_id VARCHAR(26), occurred_at DATETIME, '
    .'UNIQUE(tenant_id, company_id, record_ref, version))'
);
$suffix = bin2hex(random_bytes(6));
$tenant = new \App\Domain\Organization\Entity\Tenant('A '.$suffix, 'a-'.$suffix);
$foreign = new \App\Domain\Organization\Entity\Tenant('B '.$suffix, 'b-'.$suffix);
$company = new \App\Domain\Organization\Entity\LegalEntity($tenant, 'Empresa A', null, true);
$foreignCompany = new \App\Domain\Organization\Entity\LegalEntity($foreign, 'Empresa B', null, true);
$branch = new \App\Domain\Organization\Entity\Branch($tenant, 'Principal', 'principal', $company, true);
$foreignBranch = new \App\Domain\Organization\Entity\Branch($foreign, 'Ajena', 'ajena', $foreignCompany, true);
$owner = new \App\Domain\Identity\Entity\User('owner-'.$suffix.'@example.test', 'Owner');
$staff = new \App\Domain\Identity\Entity\User('staff-'.$suffix.'@example.test', 'Staff');
$foreignOwner = new \App\Domain\Identity\Entity\User('foreign-'.$suffix.'@example.test', 'ForeignOwner');
$ownerMembership = new \App\Domain\Identity\Entity\Membership(
    $tenant, $owner, \App\Domain\Identity\Entity\Membership::ROLE_OWNER
);
$staffMembership = new \App\Domain\Identity\Entity\Membership($tenant, $staff, 'STAFF');
$foreignMembership = new \App\Domain\Identity\Entity\Membership(
    $foreign, $foreignOwner, \App\Domain\Identity\Entity\Membership::ROLE_OWNER
);
foreach ([$tenant, $foreign, $company, $foreignCompany, $branch, $foreignBranch,
    $owner, $staff, $foreignOwner, $ownerMembership, $staffMembership, $foreignMembership] as $entity) {
    $em->persist($entity);
}
$em->flush();
$client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel);
$client->disableReboot();
$base = '/api/v1/branches/'.$branch->id().'/operations-processes-real/records';
$foreignBase = '/api/v1/branches/'.$foreignBranch->id().'/operations-processes-real/records';
$results = [];
// Sembrar datos del tenant ajeno con su propietario auténtico antes de probar denegaciones.
$client->loginUser($foreignOwner);
$crawler = $client->request('GET', '/admin');
$foreignCsrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
$client->jsonRequest('POST', $foreignBase,
    ['record_ref'=>'extranjero','kind'=>'PROCESO'], ['HTTP_X_CSRF_TOKEN'=>$foreignCsrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->loginUser($owner);
$crawler = $client->request('GET', '/admin');
$csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
$client->request('GET', $base);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'acta','kind'=>'PROCESO'], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $base.'/acta');
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('PATCH', $base.'/acta/state',
    ['state'=>'EN_REVISION','expected_version'=>1], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $foreignBase);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $foreignBase.'/extranjero');
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $foreignBase, ['record_ref'=>'intruso','kind'=>'PROCESO'],
    ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('PATCH', $foreignBase.'/extranjero/state',
    ['state'=>'EN_REVISION','expected_version'=>1], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'rechazo','kind'=>'PROCESO'],
    ['HTTP_X_CSRF_TOKEN'=>'invalid']);
$results[] = $client->getResponse()->getStatusCode();
$container->get(\App\Infrastructure\Tenancy\TenantContext::class)->reset();
$client->loginUser($staff);
$crawler = $client->request('GET', '/admin');
$staffCsrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
if (!is_string($staffCsrf) || $staffCsrf === '') {
    throw new \RuntimeException('CSRF de STAFF no disponible para probar autorización.');
}
$client->request('GET', $base);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $base.'/acta');
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'otro','kind'=>'PROCESO'],
    ['HTTP_X_CSRF_TOKEN'=>$staffCsrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('PATCH', $base.'/acta/state',
    ['state'=>'REQUIERE_REVISION','expected_version'=>2], ['HTTP_X_CSRF_TOKEN'=>$staffCsrf]);
$results[] = $client->getResponse()->getStatusCode();
$results[] = (int) $db->fetchOne('SELECT COUNT(*) FROM condor_operations_process_real_record');
$results[] = (int) $db->fetchOne('SELECT MAX(version) FROM condor_operations_process_real_record WHERE tenant_id = ? AND company_id = ? AND record_ref = ?', [$tenant->id(), $company->id(), 'acta']);
$results[] = (string) $db->fetchOne('SELECT state FROM condor_operations_process_real_record WHERE tenant_id = ? AND company_id = ? AND record_ref = ? ORDER BY version DESC LIMIT 1', [$tenant->id(), $company->id(), 'acta']);
$results[] = (int) $db->fetchOne('SELECT COUNT(*) FROM condor_operations_process_real_record WHERE tenant_id = ?', [$foreign->id()]);
$results[] = (int) $db->fetchOne('SELECT MAX(version) FROM condor_operations_process_real_record WHERE tenant_id = ? AND company_id = ? AND record_ref = ?', [$foreign->id(), $foreignCompany->id(), 'extranjero']);
$results[] = (string) $db->fetchOne('SELECT state FROM condor_operations_process_real_record WHERE tenant_id = ? AND company_id = ? AND record_ref = ? ORDER BY version DESC LIMIT 1', [$foreign->id(), $foreignCompany->id(), 'extranjero']);
$results[] = (int) $db->fetchOne('SELECT COUNT(*) FROM condor_operations_process_real_record WHERE record_ref IN (?, ?)', ['otro','intruso']);
$client->loginUser($owner);
putenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED=false');
$client->request('GET', $base);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'off','kind'=>'PROCESO'],
    ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$results[] = (int) $db->fetchOne('SELECT COUNT(*) FROM condor_operations_process_real_record');
echo json_encode($results, JSON_THROW_ON_ERROR);
'''
        import os
        env = {**os.environ, "APP_ENV": "test", "APP_DEBUG": "0",
               "CONDOR_EPHEMERAL_CACHE": "1"}
        php = shutil.which("php") or "php"
        run = subprocess.run(
            [php, "-r", "require $argv[1]; " + script,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=90,
        )
        self.assertEqual(0, run.returncode, run.stderr)
        observations = json.loads(run.stdout)
        self.assertEqual([201, 200, 201, 200, 200], observations[:5], "owner")
        self.assertEqual([404] * 4, observations[5:9], "foreign tenant")
        self.assertEqual([403] * 5, observations[9:14], "staff and rejected CSRF")
        self.assertEqual([3, 2, "EN_REVISION", 1, 1, "PENDIENTE", 0],
                         observations[14:21], "append-only audit")
        self.assertEqual([404, 404, 3], observations[21:], "server flag OFF")


    def test_controller_guards_and_pagination_are_declared(self):
        php = API.read_text()
        for route in ("list", "detail", "create", "state"):
            with self.subTest(route=route):
                self.assertIn(f"name: 'api_operations_process_real_{route}'", php)
        required_guards = {
            "strict server flag": "getenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED') === 'true'",
            "deny by default": "'feature_unavailable'",
            "active owner": "Membership::ROLE_OWNER",
            "csrf": "requireCsrf($request)",
            "company belongs to tenant": "$legalEntity?->tenant()->id() !== $tenant->id()",
            "pagination": "$request->query->get('after')",
        }
        for name, snippet in required_guards.items():
            with self.subTest(guard=name):
                self.assertIn(snippet, php)
        sql = SERVICE.read_text()
        self.assertIn("NOT EXISTS (SELECT 1 FROM", sql)
        self.assertIn("newer.tenant_id = p.tenant_id", sql)
        self.assertIn("p.company_id = :company", sql)

    def test_expand_only_migration_and_privacy_inventory(self):
        source = MIGRATION.read_text()
        for fragment in ("CREATE TABLE condor_operations_process_real_record",
                         "FOREIGN KEY (tenant_id, company_id)",
                         "IrreversibleMigration", "'EN_REVISION'"):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, source)
        self.assertNotIn("DROP TABLE", source.split("public function down", 1)[-1])
        manifest = json.loads((ROOT / "datos.yml").read_text())
        self.assertEqual("construccion", manifest["phase"])
        self.assertTrue(all(manifest["d063_attestation"].values()))
        record = next(x for x in manifest["treatments"]
                      if x["id"] == "operations_processes_real_records")
        self.assertEqual("review_required", record["basis"])
        self.assertEqual([], record["providers"])


if __name__ == "__main__":
    unittest.main()

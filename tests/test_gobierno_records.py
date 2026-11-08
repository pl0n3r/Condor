#!/usr/bin/env python3
"""Gobierno corporativo #1664: isolation, immutable audit, deny-by-default and D-059."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "src"
API = PHP / "Http/Controller/GovernanceController.php"
SERVICE = PHP / "Application/Governance/GovernanceRecordService.php"
MIGRATION = ROOT / "migrations/Version20261106136000.php"
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
    'CREATE TABLE condor_governance_real_record (id VARCHAR(26) PRIMARY KEY, '
    .'tenant_id VARCHAR(26), company_id VARCHAR(26), record_ref VARCHAR(64), '
    .'kind VARCHAR(20), state VARCHAR(20), version INT, '
    .'previous_id VARCHAR(26), actor_id VARCHAR(26), occurred_at DATETIME, '
    .'UNIQUE(tenant_id, company_id, record_ref, version))'
);
$records = new \App\Application\Governance\GovernanceRecordService($db);
"""


class GovernancePersistenceTests(unittest.TestCase):
    def php_case(self, script: str):
        php_bin = shutil.which("php")
        self.assertIsNotNone(php_bin, "PHP required for this contract")
        loader = ROOT / "vendor/autoload.php"
        self.assertTrue(loader.is_file(), "Composer autoload required")
        result = subprocess.run(
            [php_bin, "-r", "require $argv[1]; " + DB_SETUP + script, str(loader)],
            cwd=ROOT, capture_output=True, text=True, timeout=35, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_tenant_and_company_scope_with_append_only_audit(self):
        result = self.php_case(f"""
$t = '{TENANT_A}'; $other = '{TENANT_B}';
$c = '{COMPANY_A}'; $second = '{COMPANY_B}';
$a = '{ACTOR}'; $input = ['record_ref' => 'riesgo', 'kind' => 'ACTA'];
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
    (int) $db->fetchOne('SELECT COUNT(*) FROM condor_governance_real_record '
        .'WHERE tenant_id=? AND company_id=?', [$t, $c]),
]);
""")
        self.assertEqual([1, 1, 1, 0, 2, True, True, 2], result)

    def test_keyset_pagination_reaches_records_beyond_first_hundred(self):
        self.assertEqual(self.php_case(f"""
$t='{TENANT_A}'; $c='{COMPANY_A}'; $a='{ACTOR}';
for ($i = 0; $i < 101; $i++) {{
    $records->add($t, $c, $a, [
        'record_ref' => sprintf('acta_%03d', $i), 'kind' => 'ACTA',
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
    $records->add($invalid, $c, $a, ['record_ref'=>'acta','kind'=>'ACTA']);
    echo json_encode(false);
}} catch (\\DomainException $error) {{
    echo json_encode(true);
}}
"""), True)

    def test_never_accept_clinical_data_duplicate_or_auto_approval(self):
        self.assertEqual(self.php_case(f"""
$t='{TENANT_A}'; $c='{COMPANY_A}'; $a='{ACTOR}';
$input=['record_ref'=>'inspeccion','kind'=>'ACUERDO'];
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
putenv('GOVERNANCE_REAL_DATA_ENABLED=true');
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
$tool = new \Doctrine\ORM\Tools\SchemaTool($em);
$tool->createSchema($em->getMetadataFactory()->getAllMetadata());
$db = $em->getConnection();
$db->executeStatement(
    'CREATE TABLE condor_governance_real_record (id VARCHAR(26) PRIMARY KEY, '
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
$ownerMembership = new \App\Domain\Identity\Entity\Membership(
    $tenant, $owner, \App\Domain\Identity\Entity\Membership::ROLE_OWNER
);
$staffMembership = new \App\Domain\Identity\Entity\Membership($tenant, $staff, 'STAFF');
foreach ([$tenant, $foreign, $company, $foreignCompany, $branch, $foreignBranch,
    $owner, $staff, $ownerMembership, $staffMembership] as $entity) {
    $em->persist($entity);
}
$em->flush();
$client = new \Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel);
$client->loginUser($owner);
$crawler = $client->request('GET', '/admin');
$csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
$base = '/api/v1/branches/'.$branch->id().'/governance-real/records';
$foreignBase = '/api/v1/branches/'.$foreignBranch->id().'/governance-real/records';
$results = [];
$client->request('GET', $base);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'acta','kind'=>'ACTA'], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $base.'/acta');
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('PATCH', $base.'/acta/state',
    ['state'=>'EN_REVISION','expected_version'=>1], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $foreignBase);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('PATCH', $foreignBase.'/acta/state',
    ['state'=>'REQUIERE_REVISION','expected_version'=>2], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'rechazo','kind'=>'ACTA'],
    ['HTTP_X_CSRF_TOKEN'=>'invalid']);
$results[] = $client->getResponse()->getStatusCode();
$client->loginUser($staff);
$client->request('GET', $base);
$results[] = $client->getResponse()->getStatusCode();
$client->request('GET', $base.'/acta');
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'otro','kind'=>'ACTA'],
    ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('PATCH', $base.'/acta/state',
    ['state'=>'REQUIERE_REVISION','expected_version'=>2], ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$results[] = (int) $db->fetchOne('SELECT COUNT(*) FROM condor_governance_real_record');
$client->loginUser($owner);
putenv('GOVERNANCE_REAL_DATA_ENABLED=false');
$client->request('GET', $base);
$results[] = $client->getResponse()->getStatusCode();
$client->jsonRequest('POST', $base, ['record_ref'=>'off','kind'=>'ACTA'],
    ['HTTP_X_CSRF_TOKEN'=>$csrf]);
$results[] = $client->getResponse()->getStatusCode();
$results[] = (int) $db->fetchOne('SELECT COUNT(*) FROM condor_governance_real_record');
echo json_encode($results, JSON_THROW_ON_ERROR);
'''
        import os
        php_bin = shutil.which("php")
        self.assertIsNotNone(php_bin, "PHP required")
        env = dict(os.environ)
        env.update(APP_ENV="test", APP_DEBUG="0", CONDOR_EPHEMERAL_CACHE="1")
        result = subprocess.run(
            [php_bin, "-r", "require $argv[1]; " + script,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, timeout=90,
            check=False, env=env,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            [200, 201, 200, 200, 404, 404, 403, 403, 403, 403, 403, 2, 404, 404, 2],
            json.loads(result.stdout),
        )

    def test_http_routes_are_owner_only_off_by_default(self):
        source = API.read_text(encoding="utf-8")
        self.assertEqual(4, source.count("name: 'api_governance_real_"))
        for name in (
            "governanceRealList", "governanceRealView",
            "governanceRealCreate", "governanceRealState",
        ):
            self.assertIn("function " + name, source)
        self.assertEqual(source.count("($off = $this->governanceRealDisabled())"), 2)
        self.assertIn("getenv('GOVERNANCE_REAL_DATA_ENABLED') === 'true'", source)
        self.assertIn("'feature_unavailable'", source)
        self.assertIn("Membership::ROLE_OWNER", source)
        self.assertIn("governanceValidateCsrf($request)", source)
        self.assertIn("$legalEntity?->tenant()->id() !== $tenant->id()", source)
        self.assertIn("'inventory.view'", source)
        self.assertIn("'inventory.create'", source)
        self.assertIn("'inventory.update'", source)
        self.assertIn("$request->query->get('after')", source)
        self.assertIn("->page($tenant, $company, $after)", source)
        statement = SERVICE.read_text(encoding="utf-8")
        for token in (
            "entry.tenant_id = :tenant", "entry.company_id = :company",
            "tenant_id = :tenant AND company_id = :company",
            "rev.tenant_id = entry.tenant_id",
        ):
            self.assertIn(token, statement)

    def test_expand_only_schema_and_privacy_inventory(self):
        ddl = MIGRATION.read_text(encoding="utf-8")
        for token in (
            "CREATE TABLE condor_governance_real_record",
            "FOREIGN KEY (tenant_id, company_id)",
            "'EN_REVISION'", "IrreversibleMigration",
        ):
            self.assertIn(token, ddl)
        document = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        treatment = [
            item for item in document["treatments"]
            if item["id"] == "governance_real_records"
        ]
        self.assertEqual(1, len(treatment))
        self.assertEqual("review_required", treatment[0]["basis"])
        self.assertEqual([], treatment[0]["providers"])
        self.assertIn("throw new IrreversibleMigration", ddl)
        self.assertNotIn('executeStatement("DROP TABLE', ddl)
        self.assertEqual("construccion", document["phase"])
        self.assertEqual(
            {"nothing_live": True, "no_real_customer_data": True},
            document["d063_attestation"],
        )


if __name__ == "__main__":
    unittest.main()

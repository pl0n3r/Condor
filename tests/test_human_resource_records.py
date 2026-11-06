#!/usr/bin/env python3
"""RRHH #1650: DB isolation, append-only audit, guards and existing migration safety."""
import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "src/Http/Controller/HumanResourcesController.php"
SERVICE = ROOT / "src/Application/HumanResources/HumanResourceRecordService.php"
ENTITY = ROOT / "src/Domain/HumanResources/Entity/HumanResourceRecord.php"
RISK_MIGRATION = ROOT / "migrations/Version20261106120000.php"

T = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
U = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
C = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
D = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
A = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"
SETUP = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$conn->executeStatement('CREATE TABLE condor_hr_real_record (
id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(26), company_id VARCHAR(26),
record_ref VARCHAR(64), kind VARCHAR(24), collaborator_ref VARCHAR(64),
position_ref VARCHAR(64), state VARCHAR(20), version INT, previous_id VARCHAR(26),
actor_id VARCHAR(26), occurred_at DATETIME,
UNIQUE(tenant_id,company_id,record_ref,version))');
$hr = new \App\Application\HumanResources\HumanResourceRecordService($conn);
"""


class HumanResourceRecordsTests(unittest.TestCase):
    def php(self, program: str):
        self.assertIsNotNone(shutil.which("php"), "PHP CLI requerido")
        self.assertTrue((ROOT / "vendor/autoload.php").exists(), "Composer install requerido")
        result = subprocess.run(
            ["php", "-r", "require $argv[1]; " + SETUP + program,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_company_isolation_and_audit(self):
        self.assertEqual([1, 1, 1, 0, 2, True, True], self.php(r"""
$t='""" + T + r"""'; $a='""" + A + r"""'; $c='""" + C + r"""';
$p=['record_ref'=>'one','kind'=>'vinculacion',
    'collaborator_ref'=>'employeeone','position_ref'=>'operator'];
$one=$hr->create($t,$c,$a,$p);
$hr->create($t,'""" + D + r"""',$a,$p);
$hr->create('""" + U + r"""',$c,$a,$p);
$next=$hr->changeState($t,$c,$a,'one','EN_REVISION',1);
echo json_encode([
count($hr->listRecords($t,$c)),
count($hr->listRecords($t,'""" + D + r"""')),
count($hr->listRecords('""" + U + r"""',$c)),
count($hr->listRecords('""" + U + r"""','""" + D + r"""')),
(int)$conn->fetchOne('SELECT COUNT(*) FROM condor_hr_real_record
WHERE tenant_id=? AND company_id=?',[$t,$c]),
$next['previous_id']===$one['id'], $next['actor_id']===$a
]);
"""))

    def test_rejected_alias_duplicates_and_statuses(self):
        self.assertEqual([True, True, True, True], self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $a='""" + A + r"""';
$p=['record_ref'=>'one','kind'=>'novedad',
    'collaborator_ref'=>'employeeone','position_ref'=>'operator'];
$hr->create($t,$c,$a,$p); $checks=[];
foreach ([
fn()=>$hr->create($t,$c,$a,$p),
fn()=>$hr->changeState($t,$c,$a,'one','APROBADO',1),
fn()=>$hr->changeState($t,$c,$a,'one','EN_REVISION',23),
fn()=>$hr->create($t,$c,$a,[...$p,'collaborator_ref'=>'person@example.com'])
] as $run) {
    try {$run(); $checks[]=false;} catch (\DomainException $e) {$checks[]=true;}
}
echo json_encode($checks);
"""))

    def test_http_off_by_default_scoped_and_csrf(self):
        code = PHP.read_text()
        self.assertEqual(4, code.count("($off = $this->realDisabled())"))
        self.assertIn("getenv('HR_REAL_DATA_ENABLED') === 'true'", code)
        self.assertIn("'feature_unavailable'", code)
        for permission in ("users.view", "users.create", "users.update"):
            self.assertIn(f"authorizedBranchScope($branchId, '{permission}')", code)
        self.assertGreaterEqual(code.count("$this->requireCsrf($request)"), 4)
        self.assertIn("$company->tenant()->id() !== $tenant->id()", code)
        self.assertNotIn("getSession()", SERVICE.read_text())

    def test_m12_migration_not_overwritten(self):
        sql = RISK_MIGRATION.read_text()
        self.assertIn("condor_risk_record", sql)
        self.assertIn("condor_risk_audit", sql)
        self.assertEqual(2, len(re.findall(r"\bCREATE TABLE\b", sql)))
        self.assertIn("condor_hr_real_record", ENTITY.read_text())
        # A separate new migration and privacy registry are required for go-live.


if __name__ == "__main__":
    unittest.main()

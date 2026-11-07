#!/usr/bin/env python3
"""#1656: PHP/SQLite storage and fail-closed API contract; only opaque test identifiers."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/SocialSecurityController.php"
SERVICE = ROOT / "src/Application/SocialSecurity/SocialSecurityRecordService.php"
MIGRATION = ROOT / "migrations/Version20261106132000.php"
T = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
U = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
C = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
D = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
A = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"
BOOT = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$conn->executeStatement('CREATE TABLE condor_social_security_real_record (
id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(26), company_id VARCHAR(26),
record_ref VARCHAR(64), category VARCHAR(20), state VARCHAR(20), version INT,
previous_id VARCHAR(26), actor_id VARCHAR(26), occurred_at DATETIME,
UNIQUE(tenant_id, company_id, record_ref, version))');
$social = new \App\Application\SocialSecurity\SocialSecurityRecordService($conn);
"""


class SocialSecurityRecordTests(unittest.TestCase):
    def php(self, program):
        self.assertTrue(shutil.which("php"), "PHP CLI required")
        self.assertTrue((ROOT / "vendor/autoload.php").is_file(), "Composer required")
        result = subprocess.run(
            ["php", "-r", "require $argv[1]; " + BOOT + program,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        return json.loads(result.stdout)

    def test_tenant_company_isolation_and_audit(self):
        self.assertEqual([1, 1, 1, 0, 2, True, True], self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $a='""" + A + r"""';
$p=['record_ref'=>'sample','category'=>'aporte'];
$one=$social->create($t,$c,$a,$p);
$social->create($t,'""" + D + r"""',$a,$p);
$social->create('""" + U + r"""',$c,$a,$p);
$next=$social->changeState($t,$c,$a,'sample','REQUIERE_REVISION',1);
echo json_encode([
count($social->listRecords($t,$c)),
count($social->listRecords($t,'""" + D + r"""')),
count($social->listRecords('""" + U + r"""',$c)),
count($social->listRecords('""" + U + r"""','""" + D + r"""')),
(int)$conn->fetchOne('SELECT COUNT(*) FROM condor_social_security_real_record WHERE tenant_id=? AND company_id=?',[$t,$c]),
$next['previous_id']===$one['id'], $next['actor_id']===$a]);
"""))

    def test_invalid_transitions_duplicates_and_personal_data(self):
        self.assertEqual([True] * 6, self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $a='""" + A + r"""';
$p=['record_ref'=>'sample','category'=>'afiliacion'];
$social->create($t,$c,$a,$p);
$result=[];
foreach ([
fn()=>$social->create($t,$c,$a,$p),
fn()=>$social->create($t,$c,$a,[...$p,'record_ref'=>'person@example.com']),
fn()=>$social->create($t,$c,$a,[...$p,'category'=>'pago_real']),
fn()=>$social->changeState($t,$c,$a,'sample','APROBADO',1),
fn()=>$social->changeState($t,$c,$a,'sample','REQUIERE_REVISION',15),
fn()=>$social->changeState($t,$c,$a,'notfound','REQUIERE_REVISION',1)
] as $operation) {
try {$operation(); $result[]=false;} catch (\DomainException $e) {$result[]=true;}
}
echo json_encode($result);
"""))

    def test_feature_flag_permissions_csrf(self):
        src = CONTROLLER.read_text()
        self.assertEqual(4, src.count("($off = $this->realDisabled())"))
        self.assertIn("getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true'", src)
        self.assertIn("'feature_unavailable'", src)
        for permission in ("users.view", "users.create", "users.update"):
            self.assertIn(f"recordScope($branchId, '{permission}')", src)
        self.assertGreaterEqual(src.count("$this->requireCsrf($request)"), 3)
        self.assertIn("$company->tenant()->id() !== $tenant->id()", src)
        self.assertIn("tenant_id = ? AND company_id = ?", SERVICE.read_text())

    def test_migration_and_privacy(self):
        migration = MIGRATION.read_text()
        self.assertIn("CREATE TABLE condor_social_security_real_record", migration)
        self.assertIn("FOREIGN KEY (tenant_id, company_id)", migration)
        self.assertIn("IrreversibleMigration", migration)
        data = json.loads((ROOT / "datos.yml").read_text())
        records = [t for t in data["treatments"] if t["id"] == "social_security_real_records"]
        self.assertEqual(1, len(records))
        self.assertEqual("review_required", records[0]["basis"])
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""#1658: real DBAL finance-case contract and strict feature flag using invented IDs."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CTRL = ROOT / "src/Http/Controller/ComplianceController.php"
SERVICE = ROOT / "src/Application/Finance/OccupationalSafetyRecordService.php"
MIGRATION = ROOT / "migrations/Version20261106134000.php"
T = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
U = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
C = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
D = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
A = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"
SETUP = r"""
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE condor_ohs_real_record (
id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(26), company_id VARCHAR(26),
record_ref VARCHAR(64), kind VARCHAR(20), state VARCHAR(20), version INT,
previous_id VARCHAR(26), actor_id VARCHAR(26), occurred_at DATETIME,
UNIQUE(tenant_id, company_id, record_ref, version))');
$s = new \App\Application\Finance\OccupationalSafetyRecordService($db);
"""


class OccupationalSafetyRecordContractTests(unittest.TestCase):
    def php(self, script: str):
        self.assertTrue(shutil.which("php"), "PHP CLI required")
        self.assertTrue((ROOT / "vendor/autoload.php").is_file(), "Composer autoload required")
        cmd = ["php", "-r", "require $argv[1]; " + SETUP + script,
               str(ROOT / "vendor/autoload.php")]
        result = subprocess.run(cmd, cwd=ROOT, text=True, capture_output=True,
                                timeout=35, check=False)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        return json.loads(result.stdout)

    def test_isolation_history_and_actor(self):
        self.assertEqual([1, 1, 1, 0, 2, True, True], self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $actor='""" + A + r"""';
$p=['record_ref'=>'revision','kind'=>'INSPECCION'];
$first=$s->add($t,$c,$actor,$p);
$s->add($t,'""" + D + r"""',$actor,$p);
$s->add('""" + U + r"""',$c,$actor,$p);
$next=$s->transition($t,$c,$actor,'revision','REQUIERE_REVISION',1);
echo json_encode([
count($s->all($t,$c)), count($s->all($t,'""" + D + r"""')),
count($s->all('""" + U + r"""',$c)),count($s->all('""" + U + r"""','""" + D + r"""')),
(int)$db->fetchOne('SELECT COUNT(*) FROM condor_ohs_real_record WHERE tenant_id=? AND company_id=?',[$t,$c]),
$next['previous_id']===$first['id'],$next['actor_id']===$actor
]);
"""))

    def test_rejects_pii_replays_and_invalid_states(self):
        self.assertEqual([True] * 7, self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $actor='""" + A + r"""';
$p=['record_ref'=>'revision','kind'=>'CONTROL']; $s->add($t,$c,$actor,$p);
$out=[];
foreach ([
fn()=>$s->add($t,$c,$actor,$p),
fn()=>$s->add($t,$c,$actor,[...$p,'record_ref'=>'person@example.com']),
fn()=>$s->add($t,$c,$actor,[...$p,'kind'=>'EVALUACION_MEDICA']),
fn()=>$s->transition($t,$c,$actor,'revision','APROBADO',1),
fn()=>$s->transition($t,$c,$actor,'revision','REQUIERE_REVISION',17),
fn()=>$s->transition($t,$c,$actor,'missing','REQUIERE_REVISION',1),
fn()=>$s->add($t,$c,$actor,[...$p,'nombre'=>'persona'])
] as $step) {try {$step();$out[]=false;} catch (\DomainException $e) {$out[]=true;}}
echo json_encode($out);
"""))

    def test_controller_off_scope_permissions_and_csrf(self):
        src = CTRL.read_text(encoding="utf-8")
        self.assertEqual(2, src.count("($off = $this->occupationalRealDisabled())"))
        self.assertIn("occupationalRealRead($branchId", src)
        self.assertIn("occupationalRealWrite($branchId", src)
        self.assertIn("getenv('OHS_REAL_DATA_ENABLED') === 'true'", src)
        self.assertIn("'feature_unavailable'", src)
        for permission in ("settings.view", "settings.create", "settings.update"):
            self.assertIn(f"'{permission}'", src)
        self.assertIn("Membership::ROLE_OWNER", src)
        self.assertGreaterEqual(src.count("$this->requireCsrf($request)"), 3)
        self.assertIn("$entity->tenant()->id() !== $tenant->id()", src)
        self.assertIn("tenant_id = ? AND company_id = ?", SERVICE.read_text(encoding="utf-8"))

    def test_migration_and_legal_inventory(self):
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE condor_ohs_real_record", migration)
        self.assertIn("FOREIGN KEY (tenant_id, company_id)", migration)
        self.assertIn("IrreversibleMigration", migration)
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        rows = [x for x in data["treatments"] if x["id"] == "occupational_safety_real_records"]
        self.assertEqual(1, len(rows))
        self.assertEqual("review_required", rows[0]["basis"])
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])


if __name__ == "__main__":
    unittest.main()

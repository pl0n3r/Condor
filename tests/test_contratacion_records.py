#!/usr/bin/env python3
"""#1654: isolated pre-live contract persistence, auditable transitions and OFF gate."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Contracts/ContractRecordService.php"
ENTITY = ROOT / "src/Domain/Contracts/Entity/ContractRecord.php"
CONTROLLER = ROOT / "src/Http/Controller/ContractsController.php"
MIGRATION = ROOT / "migrations/Version20261106131000.php"
DATA = ROOT / "datos.yml"

T = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
U = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
C = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
D = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
A = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"

SETUP = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$conn->executeStatement('CREATE TABLE condor_contract_real_record (
id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(26), company_id VARCHAR(26),
record_ref VARCHAR(64), worker_ref VARCHAR(64), template_ref VARCHAR(64), kind VARCHAR(16),
state VARCHAR(20), version INT, previous_id VARCHAR(26),
actor_id VARCHAR(26), occurred_at DATETIME,
UNIQUE(tenant_id, company_id, record_ref, version))');
$records = new \App\Application\Contracts\ContractRecordService($conn);
"""


class ContractRecordsTests(unittest.TestCase):
    def php(self, program: str):
        self.assertIsNotNone(shutil.which("php"), "PHP CLI requerido")
        self.assertTrue((ROOT / "vendor/autoload.php").exists(), "Composer install requerido")
        result = subprocess.run(
            ["php", "-r", "require $argv[1]; " + SETUP + program,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_isolation_and_append_only_actor_audit(self):
        self.assertEqual([1, 1, 1, 0, 2, 0, True, True, "EN_REVISION"], self.php(r"""
$t='""" + T + r"""'; $a='""" + A + r"""'; $c='""" + C + r"""';
$p=['record_ref'=>'octobertest','worker_ref'=>'workerone','template_ref'=>'october','kind'=>'contract'];
$one=$records->create($t,$c,$a,$p);
$records->create($t,'""" + D + r"""',$a,$p);
$records->create('""" + U + r"""',$c,$a,$p);
$next=$records->changeState($t,$c,$a,'octobertest','EN_REVISION',1);
echo json_encode([
  count($records->listRecords($t,$c)),
  count($records->listRecords($t,'""" + D + r"""')),
  count($records->listRecords('""" + U + r"""',$c)),
  count($records->listRecords('""" + U + r"""','""" + D + r"""')),
  (int)$conn->fetchOne('SELECT COUNT(*) FROM condor_contract_real_record WHERE tenant_id=? AND company_id=?',[$t,$c]),
  $records->detail('""" + U + r"""','""" + D + r"""','octobertest')===null ? 0 : 1,
  $next['previous_id']===$one['id'],
  $next['actor_id']===$a,
  $records->detail($t,$c,'octobertest')['state']
]);"""))

    def test_alias_duplicates_and_invalid_transitions_fail_closed(self):
        self.assertEqual([True, True, True, True, True, True, True], self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $a='""" + A + r"""';
$p=['record_ref'=>'october','worker_ref'=>'workerone','template_ref'=>'october','kind'=>'contract'];
$records->create($t,$c,$a,$p);
$checks=[];
foreach ([
  fn()=>$records->create($t,$c,$a,$p),
  fn()=>$records->changeState($t,$c,$a,'october','PAGADO',1),
  fn()=>$records->changeState($t,$c,$a,'october','EN_REVISION',20),
  fn()=>$records->changeState($t,$c,$a,'october','BORRADOR',1),
  fn()=>$records->create($t,$c,$a,[...$p,'worker_ref'=>'person@example.com']),
  fn()=>$records->create($t,$c,$a,[...$p,'amount_minor'=>50000]),
  fn()=>$records->detail($t,$c,'person@example.com'),
] as $run) {
  try { $run(); $checks[]=false; } catch (\DomainException $e) {$checks[]=true;}
}
echo json_encode($checks);
"""))

    def test_stale_version_and_cross_company_detail(self):
        self.assertEqual([True, True, True], self.php(r"""
$t='""" + T + r"""'; $c='""" + C + r"""'; $a='""" + A + r"""';
$p=['record_ref'=>'november','worker_ref'=>'workerone','template_ref'=>'nov','kind'=>'contract'];
$records->create($t,$c,$a,$p);
$records->changeState($t,$c,$a,'november','EN_REVISION',1);
try {$records->changeState($t,$c,$a,'november','BORRADOR',1);$old=false;}
catch(\DomainException $e) {$old=true;}
echo json_encode([
 $old,
 $records->detail($t,'""" + D + r"""','november')===null,
 $records->detail('""" + U + r"""',$c,'november')===null
]);
"""))

    def test_http_guards_real_off_permissions_csrf(self):
        text = CONTROLLER.read_text()
        self.assertEqual(4, text.count("($off = $this->realDisabled())"))
        self.assertIn("getenv('CONTRACTS_REAL_DATA_ENABLED') === 'true'", text)
        self.assertIn("'feature_unavailable'", text)
        self.assertIn("Response::HTTP_NOT_FOUND", text)
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn(f"realScope($branchId, '{permission}')", text)
        self.assertGreaterEqual(text.count("$this->requireCsrf($request)"), 3)
        self.assertIn("Membership::ROLE_OWNER", text)
        self.assertIn("$company->tenant()->id() !== $tenant->id()", text)
        self.assertIn("real-records", text)
        self.assertIn("CONDOR_CONTRACTS_SYNTHETIC_API", text)
        self.assertNotIn("getSession()", SERVICE.read_text())

    def test_migration_and_privacy_guard(self):
        migration = MIGRATION.read_text()
        self.assertEqual(1, migration.count("CREATE TABLE"))
        for key in ("condor_contract_real_record", "uniq_contract_scope_version",
                    "FOREIGN KEY (tenant_id, company_id)",
                    "REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT",
                    "idx_contract_company_state", "actor_id VARCHAR(26)",
                    "previous_id VARCHAR(26)", "IrreversibleMigration",
                    "LOCK TABLES condor_contract_real_record WRITE"):
            self.assertIn(key, migration)
        data = json.loads(DATA.read_text())
        self.assertEqual("construccion", data["phase"])
        self.assertEqual({"nothing_live": True, "no_real_customer_data": True},
                         data["d063_attestation"])
        rows = [x for x in data["treatments"] if x["id"] == "contracts_real_records"]
        self.assertEqual(1, len(rows))
        record = rows[0]
        for key in ("tenant_id", "company_id", "record_ref", "worker_ref",
                    "template_ref", "kind", "actor_id", "previous_id", "occurred_at"):
            self.assertIn(key, record["fields"])
        for key in ("basis", "retention", "consent"):
            self.assertEqual("review_required", record[key])
        self.assertEqual([], record["providers"])
        for file in (ENTITY, SERVICE, CONTROLLER, MIGRATION):
            check = subprocess.run(["php", "-l", str(file)],
                                   capture_output=True, text=True, timeout=10)
            self.assertEqual(0, check.returncode, check.stderr)


if __name__ == "__main__":
    unittest.main()

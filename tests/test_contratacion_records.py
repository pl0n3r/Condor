#!/usr/bin/env python3
"""Acceptance #1654: persisted contracts are scoped, append-only and OFF by default."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = {
    "entity": ROOT / "src/Domain/Contracts/Entity/ContractRecord.php",
    "service": ROOT / "src/Application/Contracts/ContractRecordService.php",
    "http": ROOT / "src/Http/Controller/ContractsController.php",
    "schema": ROOT / "migrations/Version20261106131000.php",
}
TENANT = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
OTHER_TENANT = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
COMPANY = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
OTHER_COMPANY = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
ACTOR = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"

# In-memory MariaDB-compatible schema to verify DBAL without any live credentials.
HARNESS = r"""
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE condor_contract_real_record (
 id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(26), company_id VARCHAR(26),
 record_ref VARCHAR(64), worker_ref VARCHAR(64), template_ref VARCHAR(64), kind VARCHAR(16),
 state VARCHAR(24), version INT, previous_id VARCHAR(26), actor_id VARCHAR(26),
 occurred_at DATETIME, UNIQUE(tenant_id, company_id, record_ref, version))');
$service = new \App\Application\Contracts\ContractRecordService($db);
$tenant = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
$neighbor = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
$company = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
$other = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
$actor = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';
$input = ['record_ref'=>'example','worker_ref'=>'workerone',
          'template_ref'=>'approvedtemplate','kind'=>'contract'];
"""


class PersistedContractsAcceptance(unittest.TestCase):
    def execute(self, statement: str):
        self.assertIsNotNone(shutil.which("php"), "PHP CLI es obligatorio")
        autoload = ROOT / "vendor/autoload.php"
        self.assertTrue(autoload.is_file(), "Composer debe instalar dependencias")
        completed = subprocess.run(
            ["php", "-r", "require $argv[1]; " + HARNESS + statement, str(autoload)],
            cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr + completed.stdout)
        return json.loads(completed.stdout)

    def test_two_companies_and_two_tenants_never_share_contracts(self):
        outcome = self.execute(r"""
$first = $service->create($tenant, $company, $actor, $input);
$service->create($tenant, $other, $actor, $input);
$service->create($neighbor, $company, $actor, $input);
$changed = $service->changeState($tenant, $company, $actor, 'example', 'EN_REVISION', 1);
echo json_encode([
  count($service->listRecords($tenant, $company)),
  count($service->listRecords($tenant, $other)),
  count($service->listRecords($neighbor, $company)),
  $service->detail($neighbor, $other, 'example') === null,
  $service->detail($tenant, $company, 'example')['state'],
  $changed['previous_id'] === $first['id'],
  $changed['actor_id'] === $actor,
  (int) $db->fetchOne('SELECT COUNT(*) FROM condor_contract_real_record
     WHERE tenant_id = ? AND company_id = ?', [$tenant, $company]),
]);""")
        self.assertEqual([1, 1, 1, True, "EN_REVISION", True, True, 2], outcome)

    def test_duplicates_stale_versions_invalid_aliases_and_missing_records(self):
        negatives = self.execute(r"""
$service->create($tenant, $company, $actor, $input);
$operations = [
  static fn() => $service->create($tenant, $company, $actor, $input),
  static fn() => $service->changeState($tenant, $company, $actor, 'example', 'PAGADO', 1),
  static fn() => $service->changeState($tenant, $company, $actor, 'example', 'EN_REVISION', 9),
  static fn() => $service->changeState($tenant, $company, $actor, 'example', 'REQUIERE_REVISION', 1),
  static fn() => $service->create($tenant, $company, $actor, array_replace($input, ['worker_ref'=>'person@example.com'])),
  static fn() => $service->create($tenant, $company, $actor, $input + ['amount_minor'=>50000]),
  static fn() => $service->detail($tenant, $company, 'person@example.com'),
  static fn() => $service->changeState($tenant, $company, $actor, 'missing', 'EN_REVISION', 1),
];
$result = [];
foreach ($operations as $operation) {
  try { $operation(); $result[] = false; }
  catch (\DomainException $problem) { $result[] = true; }
}
echo json_encode($result);""")
        self.assertEqual([True] * 8, negatives)

    def test_http_endpoints_share_disabled_gate_and_enforce_owner_and_csrf(self):
        controller = SOURCE["http"].read_text(encoding="utf-8")
        self.assertEqual(4, controller.count("return $this->realHandle($branchId,"))
        self.assertIn("getenv('CONTRACTS_REAL_DATA_ENABLED') !== 'true'", controller)
        self.assertIn("'feature_unavailable'", controller)
        self.assertIn("Response::HTTP_NOT_FOUND", controller)
        self.assertIn("Membership::ROLE_OWNER", controller)
        self.assertIn("$this->requireCsrf($request ??", controller)
        self.assertIn("inventory.view", controller)
        self.assertIn("inventory.create", controller)
        self.assertIn("inventory.update", controller)
        self.assertIn("priority: 20", controller)
        self.assertIn("$legalEntity->tenant()->id() !== $tenant->id()", controller)
        self.assertIn("CONDOR_CONTRACTS_SYNTHETIC_API", controller)
        self.assertNotIn("getSession()", SOURCE["service"].read_text())

    def test_migration_is_additive_and_non_destructive_for_audit_history(self):
        schema = SOURCE["schema"].read_text(encoding="utf-8")
        for evidence in (
            "CREATE TABLE condor_contract_real_record",
            "uniq_contract_scope_version", "idx_contract_company_state",
            "FOREIGN KEY (tenant_id, company_id)",
            "REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT",
            "LOCK TABLES condor_contract_real_record WRITE", "IrreversibleMigration",
            "previous_id VARCHAR(26)", "actor_id VARCHAR(26)",
        ):
            with self.subTest(evidence=evidence):
                self.assertIn(evidence, schema)
        for source in SOURCE.values():
            result = subprocess.run(
                ["php", "-l", str(source)], capture_output=True,
                text=True, timeout=15, check=False,
            )
            self.assertEqual(0, result.returncode, result.stderr)

    def test_privacy_inventory_requires_legal_review_not_live(self):
        document = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", document["phase"])
        self.assertEqual(
            {"nothing_live": True, "no_real_customer_data": True},
            document["d063_attestation"],
        )
        matching = [t for t in document["treatments"] if t["id"] == "contracts_real_records"]
        self.assertEqual(1, len(matching))
        declaration = matching[0]
        self.assertEqual([], declaration["providers"])
        self.assertEqual("usage", declaration["category"])
        for value in ("basis", "retention", "consent", "purpose"):
            self.assertEqual("review_required", declaration[value])
        for name in (
            "tenant_id", "company_id", "record_ref", "worker_ref", "template_ref",
            "kind", "actor_id", "previous_id", "occurred_at",
        ):
            self.assertIn(name, declaration["fields"])


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""#1673: new M01 schema, legal scope and privacy without touching M12."""
import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NEW = ROOT / "migrations/Version20261210120000.php"
M12 = ROOT / "migrations/Version20261106120000.php"
PRIVACY = ROOT / "datos.yml"


class HumanResourceMigrationTests(unittest.TestCase):
    def test_schema_company_scope_and_append_only_versions(self):
        code = NEW.read_text()
        self.assertEqual(1, len(re.findall(r"\bCREATE TABLE\b", code)))
        for value in (
            "CREATE TABLE condor_hr_real_record",
            "UNIQUE INDEX uniq_hr_scope_version",
            "(tenant_id, company_id, record_ref, version)",
            "INDEX idx_hr_company_state (tenant_id, company_id, state)",
            "FOREIGN KEY (tenant_id, company_id)",
            "REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT",
            "actor_id VARCHAR(26) NOT NULL",
            "occurred_at DATETIME NOT NULL",
            "CONSTRAINT chk_hr_state",
            "CONSTRAINT chk_hr_kind",
        ):
            self.assertIn(value, code)
        up = code.split("public function up", 1)[1].split("public function isTransactional", 1)[0]
        self.assertNotRegex(up, r"\b(DROP TABLE|DELETE FROM|ALTER TABLE|TRUNCATE)\b")

    def test_no_history_loss_on_rollback(self):
        code = NEW.read_text().split("public function down", 1)[1]
        for marker in (
            "LOCK TABLES condor_hr_real_record WRITE",
            "SELECT COUNT(*) FROM condor_hr_real_record",
            "IrreversibleMigration",
            "DROP TABLE condor_hr_real_record",
            "UNLOCK TABLES",
        ):
            self.assertIn(marker, code)
        self.assertIn("public function isTransactional(): bool", NEW.read_text())
        if shutil.which("php"):
            result = subprocess.run(
                ["php", "-l", str(NEW)], capture_output=True,
                text=True, timeout=10, check=False,
            )
            self.assertEqual(0, result.returncode, result.stderr)

    def test_old_risk_migration_unchanged(self):
        sql = M12.read_text()
        self.assertIn("condor_risk_record", sql)
        self.assertIn("condor_risk_audit", sql)
        self.assertEqual(2, len(re.findall(r"\bCREATE TABLE\b", sql)))
        self.assertNotIn("condor_hr_real_record", sql)

    def test_privacy_registry_is_offline_and_requires_review(self):
        data = json.loads(PRIVACY.read_text())
        self.assertEqual("construccion", data["phase"])
        self.assertEqual({"nothing_live": True, "no_real_customer_data": True},
                         data["d063_attestation"])
        treatments = [x for x in data["treatments"]
                      if x["id"] == "human_resources_real_records"]
        self.assertEqual(1, len(treatments))
        item = treatments[0]
        for name in ("tenant_id", "company_id", "record_ref",
                     "collaborator_ref", "position_ref", "actor_id", "occurred_at"):
            self.assertIn(name, item["fields"])
        for name in ("basis", "retention", "consent"):
            self.assertEqual("review_required", item[name])
        self.assertEqual([], item["providers"])


if __name__ == "__main__":
    unittest.main()

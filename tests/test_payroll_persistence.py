#!/usr/bin/env python3
"""AC-01..04: real PHP execution for review-only, synthetic payroll persistence."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
RECORD = ROOT / "src/Domain/Payroll/Entity/PayrollRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/PayrollRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261204120000.php"
DATA = ROOT / "datos.yml"

BOOT = r"""
require 'src/Domain/Payroll/Entity/PayrollRecord.php';
use App\Domain\Payroll\Entity\PayrollRecord;
$time = new DateTimeImmutable('2026-10-27T12:00:00Z');
"""


class ModuloTests(unittest.TestCase):
    def php(self, code: str) -> str:
        result = subprocess.run(
            ["php", "-n", "-d", "display_errors=stderr", "-r", BOOT + code],
            cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return result.stdout.strip()

    def test_aislamiento_por_empresa(self) -> None:
        self.assertEqual("isolated", self.php(r"""
$a = new PayrollRecord('fixture:tenant/a', 'fixture:entity/a',
    'fixture:payroll/one', 'fixture:employee/a', 'fixture:period/oct',
    'fixture:actor/admin', 15000, $time);
$b = new PayrollRecord('fixture:tenant/b', 'fixture:entity/a',
    'fixture:payroll/one', 'fixture:employee/a', 'fixture:period/oct',
    'fixture:actor/admin', 15000, $time);
if ($a->snapshot()['fingerprint'] === $b->snapshot()['fingerprint']) exit(3);
echo 'isolated';
"""))
        source = REPOSITORY.read_text()
        self.assertIn("WHERE tenant_ref = ? AND legal_entity_ref = ?", source)
        self.assertIn("$record->tenantRef() !== $tenantRef", source)
        self.assertIn("$record->legalEntityRef() !== $entityRef", source)

    def test_permisos_y_auditoria(self) -> None:
        self.assertEqual("fixture:actor/admin", self.php(r"""
$record = new PayrollRecord('fixture:tenant/a', 'fixture:entity/a',
    'fixture:payroll/one', 'fixture:employee/a', 'fixture:period/oct',
    'fixture:actor/admin', 15000, $time);
echo $record->snapshot()['actor_ref'];
"""))
        source = REPOSITORY.read_text()
        for required in ("bool $authorized", "if (!$authorized)",
                         "transactional(", "condor_payroll_audit",
                         "CREATED_FOR_REVIEW", "hash_equals("):
            self.assertIn(required, source)
        self.assertIn("ON DELETE RESTRICT", MIGRATION.read_text())
        self.assertIn("PRIMARY KEY (tenant_ref, legal_entity_ref, record_ref)",
                      MIGRATION.read_text())

    def test_sin_datos_reales(self) -> None:
        for value in ("person@example.test", "+573001234567", "1234567890"):
            with self.subTest(value=value):
                self.assertEqual("rejected", self.php(
                    r"""
try {
    new PayrollRecord('fixture:tenant/a', 'fixture:entity/a',
        'fixture:payroll/one', $value, 'fixture:period/oct',
        'fixture:actor/admin', 100, $time);
    exit(4);
} catch (DomainException $e) { echo 'rejected'; }
""".replace("$value", repr(value))
                ))
        config = json.loads(DATA.read_text())
        self.assertTrue(config["d063_attestation"]["no_real_customer_data"])
        treatment = next(t for t in config["treatments"] if t["id"] == "payroll_synthetic_records")
        self.assertEqual("review_required", treatment["purpose"])
        self.assertEqual("review_required", treatment["retention"])
        self.assertEqual([], treatment["providers"])

    def test_estados_explicitos(self) -> None:
        self.assertEqual("REQUIERE_REVISION", self.php(r"""
$record = new PayrollRecord('fixture:tenant/a', 'fixture:entity/a',
    'fixture:payroll/one', 'fixture:employee/a', 'fixture:period/oct',
    'fixture:actor/admin', 100, $time);
echo $record->snapshot()['state'];
"""))
        source = RECORD.read_text().lower()
        for forbidden in ("aprobado", "pagado", "enviado", "mail(", "curl_", "httpclient"):
            self.assertNotIn(forbidden, source)
        self.assertIn("IrreversibleMigration", MIGRATION.read_text())
        for target in (RECORD, REPOSITORY, MIGRATION):
            proc = subprocess.run(["php", "-n", "-l", str(target)],
                                  cwd=ROOT, capture_output=True, text=True, timeout=20)
            self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)


if __name__ == "__main__":
    unittest.main()

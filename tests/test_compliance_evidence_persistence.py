#!/usr/bin/env python3
"""#1038: executable synthetic compliance persistence acceptance with real DBAL.

Run with: python3 tests/test_compliance_evidence_persistence.py
SQLite is isolated in memory; production migration is exercised separately in
the MariaDB backend CI. No personal/company data or production credentials.
"""
from __future__ import annotations

import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Compliance/Entity/ComplianceRecord.php"
REPO = ROOT / "src/Infrastructure/Persistence/ComplianceRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261102120000.php"

# DBAL exercises the actual repository against a disposable SQL connection.
# Schema columns and the composite uniqueness guard mirror the additive migration.
SQLITE_SETUP = r"""
if (!extension_loaded('pdo_sqlite')) {
    throw new \RuntimeException('La extensión PDO SQLite es requerida por la prueba DBAL.');
}
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement('CREATE TABLE condor_compliance_record (
    id VARCHAR(26) NOT NULL PRIMARY KEY,
    tenant_ref VARCHAR(80) NOT NULL,
    company_ref VARCHAR(80) NOT NULL,
    obligation_ref VARCHAR(80) NOT NULL,
    evidence_ref VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    version INTEGER NOT NULL,
    previous_record_id VARCHAR(26) DEFAULT NULL,
    previous_status VARCHAR(20) DEFAULT NULL,
    audit_action VARCHAR(20) NOT NULL,
    actor_ref VARCHAR(80) NOT NULL,
    occurred_at DATETIME NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    UNIQUE (tenant_ref, company_ref, obligation_ref, version)
)');
$repo = new \App\Infrastructure\Persistence\ComplianceRecordRepository($conn);
"""


class ModuloTests(unittest.TestCase):
    def php(self, code: str, dbal: bool = True) -> object:
        source = "require $argv[1]; " + (SQLITE_SETUP if dbal else "") + code
        completed = subprocess.run(
            ["php", "-r", source, str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=35,
        )
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
        return json.loads(completed.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        value = self.php(r"""
$a = \App\Domain\Compliance\Entity\ComplianceRecord::open(
    'fixture-tenant-a', 'fixture-company-a', 'fixture-obligation-one',
    'fixture-evidence-one', 'fixture-actor-owner'
);
$b = \App\Domain\Compliance\Entity\ComplianceRecord::open(
    'fixture-tenant-a', 'fixture-company-b', 'fixture-obligation-one',
    'fixture-evidence-one', 'fixture-actor-owner'
);
$c = \App\Domain\Compliance\Entity\ComplianceRecord::open(
    'fixture-tenant-b', 'fixture-company-a', 'fixture-obligation-one',
    'fixture-evidence-one', 'fixture-actor-owner'
);
foreach ([$a, $b, $c] as $record) {
    $repo->append($record, $record->tenantRef(), $record->companyRef(), 'fixture-actor-owner', true);
}
$rows = $repo->forCompany('fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
echo json_encode([count($rows), $rows[0]['id'] === $a->id(),
    $rows[0]['tenant_ref'], $rows[0]['company_ref'],
    count($repo->forCompany('fixture-tenant-a', 'fixture-company-b', 'fixture-actor-owner', true)),
    count($repo->forCompany('fixture-tenant-b', 'fixture-company-a', 'fixture-actor-owner', true))]);
""")
        self.assertEqual(
            [1, True, "fixture-tenant-a", "fixture-company-a", 1, 1], value,
        )
        src = REPO.read_text(encoding="utf-8")
        self.assertIn("WHERE tenant_ref = ? AND company_ref = ?", src)
        self.assertIn("[$tenantRef, $companyRef]", src)
        self.assertIn("WHERE tenant_ref = ? AND company_ref = ? AND obligation_ref = ?", src)
        self.assertIn("(tenant_ref, company_ref, obligation_ref, version)",
                      MIGRATION.read_text(encoding="utf-8"))

    def test_permisos_y_auditoria(self) -> None:
        value = self.php(r"""
use App\Domain\Compliance\Entity\ComplianceRecord as R;
$a = R::open('fixture-tenant-a', 'fixture-company-a',
             'fixture-obligation-one', 'fixture-evidence-one', 'fixture-actor-owner');
$denyWrite = false; $denyRead = false; $denyForeign = false; $duplicate = false;
try {
    $repo->append($a, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', false);
} catch (\DomainException $e) { $denyWrite = true; }
try {
    $repo->forCompany('fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', false);
} catch (\DomainException $e) { $denyRead = true; }
try {
    $repo->append($a, 'fixture-tenant-a', 'fixture-company-b', 'fixture-actor-owner', true);
} catch (\DomainException $e) { $denyForeign = true; }
$repo->append($a, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
$b = $a->transition(R::STATUS_EN_REVISION, 'fixture-actor-reviewer', true);
$repo->append($b, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-reviewer', true);
try {
    $repo->append($a, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
} catch (\Throwable $e) { $duplicate = true; }
$rows = $repo->forCompany('fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
echo json_encode([$denyWrite, $denyRead, $denyForeign, $duplicate,
                  count($rows), $rows[1]['previous_record_id'] === $a->id(),
                  $rows[1]['previous_status'], $rows[1]['actor_ref'],
                  $rows[1]['audit_action'], $rows[1]['version'],
                  strlen($rows[1]['fingerprint'])]);
""")
        self.assertEqual(
            [True, True, True, True, 2, True, "PENDIENTE",
             "fixture-actor-reviewer", "CAMBIO_EXPLICITO", 2, 64],
            value,
        )
        src = REPO.read_text(encoding="utf-8")
        for item in ("bool $authorized", "if (!$authorized)", "transactional(",
                     "lockClause()", "previousRecordId()", "previousStatus()",
                     "$this->connection->insert(", "hash_equals("):
            self.assertIn(item, src)
        self.assertNotIn("UPDATE condor_compliance_record", src)
        self.assertNotIn("DELETE FROM condor_compliance_record", src)

    def test_sin_datos_reales(self) -> None:
        value = self.php(r"""
use App\Domain\Compliance\Entity\ComplianceRecord as R;
$invalid = [
    ['01J9PV8C8CNVQYKAFDZT4DF8P7', 'fixture-company-a', 'fixture-obligation-x',
     'fixture-evidence-x', 'fixture-actor-x'],
    ['fixture-tenant-a', 'Compañía SAS', 'fixture-obligation-x',
     'fixture-evidence-x', 'fixture-actor-x'],
    ['fixture-tenant-a', 'fixture-company-a', 'someone@example.com',
     'fixture-evidence-x', 'fixture-actor-x'],
    ['fixture-tenant-a', 'fixture-company-a', 'fixture-obligation-x',
     'NIT 900123', 'fixture-actor-x'],
    ['fixture-tenant-a', 'fixture-company-a', 'fixture-obligation-x',
     'fixture-evidence-x', 'someone@company.co'],
];
$out = [];
foreach ($invalid as $row) {
    try { $repo->append(R::open(...$row), $row[0], $row[1], $row[4], true); $out[] = false; }
    catch (\DomainException $e) { $out[] = true; }
}
echo json_encode([$out, $conn->fetchOne('SELECT COUNT(*) FROM condor_compliance_record')]);
""")
        self.assertEqual([[True] * 5, 0], value)
        self.assertIn("assertFixtureScope(", ENTITY.read_text(encoding="utf-8"))
        self.assertNotIn("#[Route", ENTITY.read_text(encoding="utf-8"))

    def test_estados_explicitos(self) -> None:
        value = self.php(r"""
use App\Domain\Compliance\Entity\ComplianceRecord as R;
$a = R::open('fixture-tenant-a', 'fixture-company-a', 'fixture-obligation-one',
             'fixture-evidence-one', 'fixture-actor-owner');
$notDirect = false; $notAutomatic = false; $terminal = false; $stale = false;
try { $a->transition(R::STATUS_CERRADA, 'fixture-actor-owner', true); }
catch (\DomainException $e) { $notDirect = true; }
$repo->append($a, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
$b = $a->transition(R::STATUS_EN_REVISION, 'fixture-actor-reviewer', true);
$repo->append($b, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-reviewer', true);
try { $b->transition(R::STATUS_CERRADA, 'fixture-actor-reviewer', false); }
catch (\DomainException $e) { $notAutomatic = true; }
$c = $b->transition(R::STATUS_CERRADA, 'fixture-actor-reviewer', true);
$repo->append($c, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-reviewer', true);
try { $c->transition(R::STATUS_EN_REVISION, 'fixture-actor-owner', true); }
catch (\DomainException $e) { $terminal = true; }
try {
    $staleEvent = $a->transition(R::STATUS_EN_REVISION, 'fixture-actor-owner', true);
    $repo->append($staleEvent, 'fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
} catch (\DomainException $e) { $stale = true; }
$before = count($repo->forCompany('fixture-tenant-a', 'fixture-company-a',
                                  'fixture-actor-owner', true));
$conn->executeStatement("UPDATE condor_compliance_record SET fingerprint = ? WHERE id = ?",
                        [str_repeat('0', 64), $c->id()]);
$corrupt = false;
try {
    $repo->forCompany('fixture-tenant-a', 'fixture-company-a', 'fixture-actor-owner', true);
} catch (\DomainException $e) { $corrupt = true; }
echo json_encode([$notDirect, $notAutomatic, $terminal, $stale,
                  $a->status(), $b->status(), $c->status(), $before, $corrupt]);
""")
        self.assertEqual(
            [True, True, True, True, "PENDIENTE", "EN_REVISION", "CERRADA", 3, True],
            value,
        )
        migration = MIGRATION.read_text(encoding="utf-8")
        up = migration.split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(1, len(re.findall(r"\bCREATE TABLE\b", up)))
        self.assertNotRegex(up, r"\b(DROP TABLE|DELETE FROM|TRUNCATE|ALTER TABLE)\b")
        down = migration.split("public function down", 1)[1]
        self.assertIn("SELECT COUNT(*) FROM condor_compliance_record", down)
        self.assertIn("$this->abortIf(", down)
        self.assertIn("DROP TABLE condor_compliance_record", down)


if __name__ == "__main__":
    unittest.main()

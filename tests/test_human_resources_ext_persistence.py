#!/usr/bin/env python3
"""M01 acceptance: segregated fixture-only records, explicit authorizations and audit.

Each case runs against a fresh SQLite database through the real Doctrine DBAL,
not mock repositories, remote services, or customer data.
"""
from __future__ import annotations

import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

BASE = Path(__file__).resolve().parents[1]
MIGRATION = BASE / "migrations/Version20261201120000.php"
REPO = BASE / "src/Infrastructure/Persistence/EmployeeRecordRepository.php"
ENTITY = BASE / "src/Domain/HumanResources/Entity/EmployeeRecord.php"

BOOTSTRAP = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'memory' => true,
]);
$conn->executeStatement(<<<'SQL'
CREATE TABLE condor_employee_record (
    id VARCHAR(26) PRIMARY KEY,
    tenant_ref VARCHAR(80), company_ref VARCHAR(80), employee_ref VARCHAR(80),
    category VARCHAR(20), state VARCHAR(20), version INTEGER,
    previous_id VARCHAR(26), previous_state VARCHAR(20),
    audit_action VARCHAR(20), actor_ref VARCHAR(80), occurred_at DATETIME,
    fingerprint VARCHAR(64),
    UNIQUE (tenant_ref, company_ref, employee_ref, version)
)
SQL);
$conn->executeStatement(<<<'SQL'
CREATE TABLE condor_employee_audit (
    event_id CHAR(32) PRIMARY KEY,
    tenant_ref VARCHAR(80), company_ref VARCHAR(80), employee_ref VARCHAR(80),
    actor_ref VARCHAR(80), event VARCHAR(40), created_at DATETIME
)
SQL);
$repository = new \App\Infrastructure\Persistence\EmployeeRecordRepository($conn);
$class = \App\Domain\HumanResources\Entity\EmployeeRecord::class;
"""


class ModuloTests(unittest.TestCase):
    def execute(self, code: str) -> object:
        autoload = BASE / "vendor/autoload.php"
        self.assertTrue(autoload.is_file(), "composer install required for DBAL acceptance")
        self.assertIsNotNone(shutil.which("php"), "PHP CLI unavailable")
        completed = subprocess.run(
            ["php", "-r", "require $argv[1];\n" + BOOTSTRAP + code, str(autoload)],
            cwd=BASE, capture_output=True, text=True, timeout=35, check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stderr + completed.stdout)
        return json.loads(completed.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        result = self.execute(r"""
$scopes = [
    ['fixture:tenant/alpha', 'fixture:company/main'],
    ['fixture:tenant/beta', 'fixture:company/main'],
    ['fixture:tenant/alpha', 'fixture:company/other'],
];
$seen = [];
foreach ($scopes as [$tenant, $company]) {
    $record = $class::open($tenant, $company, 'fixture:employee/case',
        'vacacion', 'fixture:actor/owner');
    $repository->append($record, $tenant, $company, 'fixture:actor/owner', true);
    $rows = $repository->forCompany($tenant, $company, 'fixture:actor/owner', true);
    $seen[] = [count($rows), $rows[0]['id'] === $record->id()];
}
echo json_encode($seen);
""")
        self.assertEqual([[1, True]] * 3, result)
        self.assertIn("WHERE tenant_ref = ? AND company_ref = ?", REPO.read_text())
        self.assertIn("UNIQUE INDEX uniq_employee_scope_version", MIGRATION.read_text())

    def test_permisos_y_auditoria(self) -> None:
        result = self.execute(r"""
$one = $class::open('fixture:tenant/alpha', 'fixture:company/main',
    'fixture:employee/case', 'novedad', 'fixture:actor/owner');
$denied = static function (callable $action): bool {
    try { $action(); return false; } catch (\DomainException) { return true; }
};
$blocked = [];
$blocked[] = $denied(fn() => $repository->append($one,
    'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', false));
$blocked[] = $denied(fn() => $repository->append($one,
    'fixture:tenant/beta', 'fixture:company/main', 'fixture:actor/owner', true));
$blocked[] = $denied(fn() => $repository->append($one,
    'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/other', true));
$repository->append($one, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
$two = $one->transition($class::REVIEW, 'fixture:actor/reviewer', true);
$repository->append($two, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/reviewer', true);
$blocked[] = $denied(fn() => $repository->forCompany(
    'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', false));
$blocked[] = $denied(fn() => $repository->append($one,
    'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true));
$rows = $repository->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
echo json_encode([$blocked, count($rows), $rows[1]['previous_id'] === $one->id(),
    $rows[1]['actor_ref'], $rows[1]['audit_action'],
    (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_employee_audit')]);
""")
        self.assertEqual(
            [[True] * 5, 2, True, "fixture:actor/reviewer", "CAMBIO_EXPLICITO", 3],
            result,
        )

    def test_sin_datos_reales(self) -> None:
        outcome = self.execute(r"""
$attempts = [
    ['someone@example.com', 'fixture:company/main', 'fixture:employee/case', 'vinculacion'],
    ['fixture:tenant/alpha', 'Empresa SAS', 'fixture:employee/case', 'vacacion'],
    ['fixture:tenant/alpha', 'fixture:company/main', 'fixture:employee/3115551212', 'evaluacion'],
    ['fixture:tenant/alpha', 'fixture:company/main', 'https://example.com', 'novedad'],
    ['fixture:tenant/alpha', 'fixture:company/main', 'fixture:employee/case', 'personal'],
];
$rejected = [];
foreach ($attempts as [$tenant, $company, $case, $kind]) {
    try {
        $value = $class::open($tenant, $company, $case, $kind, 'fixture:actor/owner');
        $repository->append($value, $tenant, $company, 'fixture:actor/owner', true);
        $rejected[] = false;
    } catch (\DomainException) {
        $rejected[] = true;
    }
}
echo json_encode([$rejected, (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_employee_record')]);
""")
        self.assertEqual([[True] * 5, 0], outcome)
        self.assertNotIn("#[Route", ENTITY.read_text())
        self.assertNotRegex(REPO.read_text(), r"(?i)(curl_|file_get_contents\(|httpclient)")
        up = MIGRATION.read_text().split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(2, len(re.findall(r"\bCREATE TABLE\b", up)))
        self.assertNotRegex(up, r"\b(DROP TABLE|DELETE FROM|TRUNCATE|ALTER TABLE)\b")

    def test_estados_explicitos(self) -> None:
        observed = self.execute(r"""
$start = $class::open('fixture:tenant/alpha', 'fixture:company/main',
    'fixture:employee/case', 'vinculacion', 'fixture:actor/owner');
$rejects = [];
foreach ([[$class::REVIEW, false], ['CERRADO', true]] as [$state, $approval]) {
    try { $start->transition($state, 'fixture:actor/owner', $approval); }
    catch (\DomainException) { $rejects[] = $state; }
}
$repository->append($start, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
$updated = $start->transition($class::REVIEW, 'fixture:actor/owner', true);
$repository->append($updated, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
try {
    $obsolete = $start->transition($class::REVIEW, 'fixture:actor/owner', true);
    $repository->append($obsolete, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
} catch (\DomainException) { $rejects[] = 'STALE'; }
$rows = $repository->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
$conn->executeStatement('UPDATE condor_employee_record SET fingerprint = ? WHERE id = ?',
    [str_repeat('0', 64), $updated->id()]);
try {
    $repository->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
} catch (\DomainException) { $rejects[] = 'TAMPER'; }
echo json_encode([$rejects, $start->state(), $updated->state(), count($rows)]);
""")
        self.assertEqual(
            [["REQUIERE_REVISION", "CERRADO", "STALE", "TAMPER"],
             "PENDIENTE", "REQUIERE_REVISION", 2],
            observed,
        )
        down = MIGRATION.read_text().split("public function down", 1)[1]
        for token in ("SELECT COUNT(*) FROM condor_employee_record",
                      "SELECT COUNT(*) FROM condor_employee_audit", "$this->abortIf("):
            self.assertIn(token, down)
        self.assertNotIn("CERRADO", ENTITY.read_text())


if __name__ == "__main__":
    unittest.main()

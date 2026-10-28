#!/usr/bin/env python3
"""M01: pruebas reales de persistencia sintética, aislamiento y auditoría con DBAL."""
from __future__ import annotations

import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/HumanResources/Entity/EmployeeRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/EmployeeRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261201120000.php"

DATABASE = r"""
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE condor_employee_record (
  id VARCHAR(26) PRIMARY KEY, tenant_ref VARCHAR(80), company_ref VARCHAR(80),
  employee_ref VARCHAR(80), actor_ref VARCHAR(80), category VARCHAR(20),
  state VARCHAR(20), version INTEGER, previous_id VARCHAR(26),
  previous_state VARCHAR(20), audit_action VARCHAR(20),
  occurred_at DATETIME, fingerprint VARCHAR(64),
  UNIQUE (tenant_ref,company_ref,employee_ref,version))');
$db->executeStatement('CREATE TABLE condor_employee_audit (
  event_id CHAR(32) PRIMARY KEY, tenant_ref VARCHAR(80),
  company_ref VARCHAR(80), employee_ref VARCHAR(80), actor_ref VARCHAR(80),
  event VARCHAR(40), created_at DATETIME)');
$repo = new \App\Infrastructure\Persistence\EmployeeRecordRepository($db);
$type = \App\Domain\HumanResources\Entity\EmployeeRecord::class;
$actor = 'fixture:actor/owner';
"""


class ModuloTests(unittest.TestCase):
    def run_case(self, script: str) -> object:
        autoload = ROOT / "vendor/autoload.php"
        self.assertTrue(autoload.exists(), "Composer autoload required for DBAL tests")
        self.assertIsNotNone(shutil.which("php"), "PHP CLI unavailable")
        process = subprocess.run(
            ["php", "-r", "require $argv[1];\n" + DATABASE + script, str(autoload)],
            cwd=ROOT, capture_output=True, text=True, timeout=40, check=False,
        )
        self.assertEqual(process.returncode, 0, process.stderr + process.stdout)
        return json.loads(process.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        actual = self.run_case(r"""
$contexts = [
  ['fixture:tenant/a', 'fixture:company/a'],
  ['fixture:tenant/a', 'fixture:company/b'],
  ['fixture:tenant/b', 'fixture:company/a'],
];
$counts = [];
foreach ($contexts as [$tenant, $company]) {
    $event = $type::open($tenant, $company, 'fixture:employee/same', 'vinculacion', $actor);
    $repo->append($event, $tenant, $company, $actor, true);
    $owned = $repo->forCompany($tenant, $company, $actor, true);
    $counts[] = [count($owned), $owned[0]['id'] === $event->id()];
}
echo json_encode($counts);
""")
        self.assertEqual([[1, True], [1, True], [1, True]], actual)
        self.assertIn("WHERE tenant_ref = ? AND company_ref = ?", REPOSITORY.read_text())
        self.assertIn("UNIQUE INDEX uniq_employee_scope_version", MIGRATION.read_text())

    def test_permisos_y_auditoria(self) -> None:
        result = self.run_case(r"""
$tenant = 'fixture:tenant/a';
$company = 'fixture:company/a';
$first = $type::open($tenant, $company, 'fixture:employee/test', 'novedad', $actor);
$denied = static function (callable $op): bool {
    try { $op(); } catch (\DomainException) { return true; }
    return false;
};
$checks = [
  $denied(fn() => $repo->append($first, $tenant, $company, $actor, false)),
  $denied(fn() => $repo->append($first, 'fixture:tenant/b', $company, $actor, true)),
  $denied(fn() => $repo->append($first, $tenant, $company, 'fixture:actor/other', true)),
];
$repo->append($first, $tenant, $company, $actor, true);
$second = $first->transition($type::REVIEW, 'fixture:actor/reviewer', true);
$repo->append($second, $tenant, $company, 'fixture:actor/reviewer', true);
$checks[] = $denied(fn() => $repo->forCompany($tenant, $company, $actor, false));
$checks[] = $denied(fn() => $repo->append($first, $tenant, $company, $actor, true));
$history = $repo->forCompany($tenant, $company, $actor, true);
echo json_encode([$checks, count($history),
  $history[1]['previous_id'] === $first->id(), $history[1]['actor_ref'],
  (int) $db->fetchOne('SELECT count(*) FROM condor_employee_audit')]);
""")
        self.assertEqual([[True] * 5, 2, True, "fixture:actor/reviewer", 3], result)

    def test_sin_datos_reales(self) -> None:
        verdict = self.run_case(r"""
$examples = [
  ['someone@example.com', 'fixture:company/a', 'fixture:employee/test', 'vinculacion'],
  ['fixture:tenant/a', 'Empresa SAS', 'fixture:employee/test', 'novedad'],
  ['fixture:tenant/a', 'fixture:company/a', 'fixture:employee/3115551212', 'vacacion'],
  ['fixture:tenant/a', 'fixture:company/a', 'https://example.com', 'evaluacion'],
  ['fixture:tenant/a', 'fixture:company/a', 'fixture:employee/test', 'salary'],
];
$rejected = [];
foreach ($examples as [$tenant, $company, $employee, $category]) {
  try {
    $record = $type::open($tenant, $company, $employee, $category, $actor);
    $repo->append($record, $tenant, $company, $actor, true);
    $rejected[] = false;
  } catch (\DomainException) { $rejected[] = true; }
}
echo json_encode([$rejected, (int) $db->fetchOne('SELECT count(*) FROM condor_employee_record')]);
""")
        self.assertEqual([[True] * 5, 0], verdict)
        self.assertNotIn("#[Route", ENTITY.read_text())
        self.assertNotRegex(REPOSITORY.read_text(), r"(?i)(curl_|file_get_contents\(|httpclient)")
        up = MIGRATION.read_text().split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(2, len(re.findall(r"\bCREATE TABLE\b", up)))
        self.assertNotRegex(up, r"\b(DROP TABLE|TRUNCATE|DELETE FROM)\b")

    def test_estados_explicitos(self) -> None:
        states = self.run_case(r"""
$tenant = 'fixture:tenant/a';
$company = 'fixture:company/a';
$event = $type::open($tenant, $company, 'fixture:employee/test', 'evaluacion', $actor);
$rejected = [];
foreach ([[$type::REVIEW, false], ['CERRADO', true]] as [$next, $approved]) {
  try { $event->transition($next, $actor, $approved); }
  catch (\DomainException) { $rejected[] = $next; }
}
$repo->append($event, $tenant, $company, $actor, true);
$next = $event->transition($type::REVIEW, $actor, true);
$repo->append($next, $tenant, $company, $actor, true);
try {
  $repo->append($event->transition($type::REVIEW, $actor, true),
    $tenant, $company, $actor, true);
} catch (\DomainException) { $rejected[] = 'STALE'; }
$seen = $repo->forCompany($tenant, $company, $actor, true);
$db->executeStatement('UPDATE condor_employee_record SET fingerprint = ? WHERE id = ?',
  [str_repeat('0', 64), $next->id()]);
try { $repo->forCompany($tenant, $company, $actor, true); }
catch (\DomainException) { $rejected[] = 'TAMPER'; }
echo json_encode([$rejected, $event->state(), $next->state(), count($seen)]);
""")
        self.assertEqual(
            [["REQUIERE_REVISION", "CERRADO", "STALE", "TAMPER"],
             "PENDIENTE", "REQUIERE_REVISION", 2], states,
        )
        down = MIGRATION.read_text().split("public function down", 1)[1]
        for statement in (
            "SELECT COUNT(*) FROM condor_employee_record",
            "SELECT COUNT(*) FROM condor_employee_audit", "$this->abortIf(",
        ):
            self.assertIn(statement, down)
        self.assertNotIn("'CERRADO'", ENTITY.read_text())


if __name__ == "__main__":
    unittest.main()

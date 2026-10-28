#!/usr/bin/env python3
"""#1041 M07: DBAL acceptance for synthetic finance persistence and audit."""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Finance/Entity/FinanceRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/FinanceRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261103120000.php"

SQLITE = r"""
if (!extension_loaded('pdo_sqlite')) {
    throw new \RuntimeException('Se requiere PDO SQLite para probar DBAL');
}
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement('CREATE TABLE condor_finance_record (
    id VARCHAR(26) NOT NULL PRIMARY KEY,
    tenant_ref VARCHAR(80) NOT NULL,
    legal_entity_ref VARCHAR(80) NOT NULL,
    record_ref VARCHAR(80) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    amount_decimal VARCHAR(16) NOT NULL,
    state VARCHAR(20) NOT NULL,
    version INTEGER NOT NULL,
    previous_id VARCHAR(26) DEFAULT NULL,
    previous_state VARCHAR(20) DEFAULT NULL,
    audit_action VARCHAR(20) NOT NULL,
    actor_ref VARCHAR(80) NOT NULL,
    occurred_at DATETIME NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    UNIQUE (tenant_ref, legal_entity_ref, record_ref, version)
)');
$repo = new \App\Infrastructure\Persistence\FinanceRecordRepository($conn);
"""

OPEN = r"""R::open(
    'fixture:tenant/alpha', 'fixture:legal-entity/main',
    'fixture:finance-entry/sample', 'ENTRY', '123.45', 'fixture:actor/owner'
)"""


class ModuloTests(unittest.TestCase):
    def php(self, source: str) -> object:
        self.assertTrue((ROOT / "vendor/autoload.php").exists(), "Se requiere composer install")
        completed = subprocess.run(
            ["php", "-r", "require $argv[1]; " + SQLITE + source,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, timeout=35, check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
        return json.loads(completed.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        result = self.php(r"""
use App\Domain\Finance\Entity\FinanceRecord as R;
$a = """ + OPEN + r""";
$b = R::open('fixture:tenant/beta', 'fixture:legal-entity/main',
    'fixture:finance-entry/sample', 'ENTRY', '123.45', 'fixture:actor/owner');
$c = R::open('fixture:tenant/alpha', 'fixture:legal-entity/other',
    'fixture:finance-entry/sample', 'ENTRY', '123.45', 'fixture:actor/owner');
$repo->append($a, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
$repo->append($b, 'fixture:tenant/beta', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
$repo->append($c, 'fixture:tenant/alpha', 'fixture:legal-entity/other', 'fixture:actor/owner', true);
$first = $repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
$second = $repo->forCompany('fixture:tenant/beta', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
$other = $repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/other', 'fixture:actor/owner', true);
echo json_encode([count($first), count($second), count($other),
    $first[0]['id'] === $a->id(), $second[0]['id'] === $b->id(),
    $other[0]['id'] === $c->id()]);
""")
        self.assertEqual([1, 1, 1, True, True, True], result)
        src = REPOSITORY.read_text(encoding="utf-8")
        self.assertIn("WHERE tenant_ref = ? AND legal_entity_ref = ?", src)
        self.assertIn("UNIQUE INDEX uniq_finance_scope_version", MIGRATION.read_text())

    def test_permisos_y_auditoria(self) -> None:
        result = self.php(r"""
use App\Domain\Finance\Entity\FinanceRecord as R;
$a = """ + OPEN + r""";
$unauthorized = false; $crossTenant = false; $crossActor = false; $replay = false;
try { $repo->append($a, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', false); }
catch (\DomainException $e) { $unauthorized = true; }
try { $repo->append($a, 'fixture:tenant/beta', 'fixture:legal-entity/main', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $crossTenant = true; }
try { $repo->append($a, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/other', true); }
catch (\DomainException $e) { $crossActor = true; }
$repo->append($a, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
$b = $a->sendToReview('fixture:actor/reviewer', true);
$repo->append($b, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/reviewer', true);
try { $repo->append($a, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true); }
catch (\Throwable $e) { $replay = true; }
$readDenied = false;
try { $repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', false); }
catch (\DomainException $e) { $readDenied = true; }
$rows = $repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
echo json_encode([$unauthorized, $crossTenant, $crossActor, $replay, $readDenied,
    count($rows), $rows[1]['previous_id'] === $a->id(),
    $rows[1]['previous_state'], $rows[1]['actor_ref'],
    $rows[1]['audit_action'], (int) $rows[1]['version'],
    strlen($rows[1]['fingerprint'])]);
""")
        self.assertEqual(
            [True, True, True, True, True, 2, True,
             "BORRADOR", "fixture:actor/reviewer", "CAMBIO_EXPLICITO", 2, 64],
            result,
        )
        src = REPOSITORY.read_text()
        for item in ("bool $authorized", "transactional(", "lockClause()", "hash_equals("):
            self.assertIn(item, src)
        self.assertNotIn("UPDATE condor_finance_record", src)
        self.assertNotIn("DELETE FROM condor_finance_record", src)

    def test_sin_datos_reales(self) -> None:
        result = self.php(r"""
use App\Domain\Finance\Entity\FinanceRecord as R;
$invalid = [
    ['900123456', 'fixture:legal-entity/main', 'fixture:finance-entry/one',
      'ENTRY', '15.00', 'fixture:actor/owner'],
    ['fixture:tenant/alpha', 'Compañía SAS', 'fixture:finance-entry/one',
      'ENTRY', '15.00', 'fixture:actor/owner'],
    ['fixture:tenant/alpha', 'fixture:legal-entity/main', 'someone@example.com',
      'ENTRY', '15.00', 'fixture:actor/owner'],
    ['fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:finance-entry/12345678',
      'ENTRY', '15.00', 'fixture:actor/owner'],
    ['fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:finance-entry/one',
      'ENTRY', '15.001', 'fixture:actor/owner'],
    ['fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:finance-entry/one',
      'ENTRY', '15.00', 'owner@example.com'],
    ['fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:finance-entry/one',
      'BANK', '15.00', 'fixture:actor/owner'],
];
$rejected = [];
foreach ($invalid as $row) {
    try { $repo->append(R::open(...$row), $row[0], $row[1], $row[5], true); $rejected[] = false; }
    catch (\DomainException $e) { $rejected[] = true; }
}
echo json_encode([$rejected, (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_finance_record')]);
""")
        self.assertEqual([[True] * 7, 0], result)
        self.assertNotIn("#[Route", ENTITY.read_text())
        self.assertNotRegex(ENTITY.read_text(), r"(?i)(curl_|file_get_contents\(|httpclient)")

    def test_estados_explicitos(self) -> None:
        result = self.php(r"""
use App\Domain\Finance\Entity\FinanceRecord as R;
$a = """ + OPEN + r""";
$withoutHuman = false; $stale = false; $terminal = false; $corrupt = false;
try { $a->sendToReview('fixture:actor/owner', false); }
catch (\DomainException $e) { $withoutHuman = true; }
$repo->append($a, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
$b = $a->sendToReview('fixture:actor/reviewer', true);
$repo->append($b, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/reviewer', true);
try { $b->sendToReview('fixture:actor/reviewer', true); }
catch (\DomainException $e) { $terminal = true; }
try {
    $again = $a->sendToReview('fixture:actor/owner', true);
    $repo->append($again, 'fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
} catch (\DomainException $e) { $stale = true; }
$before = count($repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true));
$conn->executeStatement('UPDATE condor_finance_record SET fingerprint = ? WHERE id = ?',
    [str_repeat('0', 64), $b->id()]);
try {
    $repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/main', 'fixture:actor/owner', true);
} catch (\DomainException $e) { $corrupt = true; }
echo json_encode([$withoutHuman, $stale, $terminal, $corrupt,
    $a->state(), $b->state(), $before]);
""")
        self.assertEqual(
            [True, True, True, True, "BORRADOR", "REQUIERE_REVISION", 2],
            result,
        )
        migration = MIGRATION.read_text(encoding="utf-8")
        up = migration.split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(1, len(re.findall(r"\bCREATE TABLE\b", up)))
        self.assertNotRegex(up, r"\b(DROP TABLE|DELETE FROM|TRUNCATE|ALTER TABLE)\b")
        down = migration.split("public function down", 1)[1]
        self.assertIn("SELECT COUNT(*) FROM condor_finance_record", down)
        self.assertIn("$this->abortIf(", down)
        self.assertIn("DROP TABLE condor_finance_record", down)


if __name__ == "__main__":
    unittest.main()

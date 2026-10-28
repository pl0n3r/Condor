#!/usr/bin/env python3
"""#1056: execute real PHP/DBAL M15 acceptance against isolated SQLite fixtures."""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Governance/Entity/GovernanceRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/GovernanceRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261108120000.php"

SETUP = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement('CREATE TABLE condor_governance_entry (
 entry_id VARCHAR(26) PRIMARY KEY, tenant_ref VARCHAR(80), company_ref VARCHAR(80),
 governance_ref VARCHAR(80), category VARCHAR(20), state VARCHAR(20), revision INTEGER,
 prior_id VARCHAR(26), actor_ref VARCHAR(80), action VARCHAR(20), recorded_at DATETIME,
 fingerprint VARCHAR(64), UNIQUE (tenant_ref, company_ref, governance_ref, revision))');
$conn->executeStatement('CREATE TABLE condor_governance_journal (
 journal_id CHAR(32) PRIMARY KEY, tenant_ref VARCHAR(80), company_ref VARCHAR(80),
 governance_ref VARCHAR(80), actor_ref VARCHAR(80), event_kind VARCHAR(32), written_at DATETIME)');
$store = new \App\Infrastructure\Persistence\GovernanceRecordRepository($conn);
"""
NAMESPACE = r"use App\Domain\Governance\Entity\GovernanceRecord as G;"
NEW = r"G::register('fixture:tenant/uno', 'fixture:company/uno', 'fixture:governance/acta', 'acta', 'fixture:actor/owner')"


class ModuloTests(unittest.TestCase):
    def execute(self, code: str) -> object:
        autoload = ROOT / "vendor/autoload.php"
        if not autoload.is_file() or shutil.which("php") is None:
            self.fail("La prueba de persistencia requiere PHP y Composer instalados.")
        script = "require $argv[1]; " + SETUP + NAMESPACE + code
        try:
            output = subprocess.check_output(
                ["php", "-r", script, str(autoload)],
                cwd=ROOT, stderr=subprocess.STDOUT, text=True, timeout=35,
            )
        except subprocess.CalledProcessError as failure:
            self.fail("Fallo en prueba PHP/DBAL:\n" + failure.output)
        return json.loads(output)

    def test_aislamiento_por_empresa(self) -> None:
        values = self.execute(r"""
$a = """ + NEW + r""";
$b = G::register('fixture:tenant/dos', 'fixture:company/uno',
    'fixture:governance/acta', 'acta', 'fixture:actor/owner');
$c = G::register('fixture:tenant/uno', 'fixture:company/dos',
    'fixture:governance/acta', 'acta', 'fixture:actor/owner');
foreach ([$a, $b, $c] as $item) {
    $store->append($item, $item->tenant(), $item->company(), $item->author(), true);
}
$alpha = $store->history('fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', true);
$beta = $store->history('fixture:tenant/dos', 'fixture:company/uno', 'fixture:actor/owner', true);
$gamma = $store->history('fixture:tenant/uno', 'fixture:company/dos', 'fixture:actor/owner', true);
echo json_encode([count($alpha), count($beta), count($gamma),
    $alpha[0]['entry_id'] === $a->id(), $beta[0]['entry_id'] === $b->id(),
    $gamma[0]['entry_id'] === $c->id()]);
""")
        self.assertEqual([1, 1, 1, True, True, True], values)
        self.assertIn("WHERE tenant_ref = ? AND company_ref = ?", REPOSITORY.read_text())
        self.assertIn("UNIQUE INDEX uniq_gov_revision", MIGRATION.read_text())

    def test_permisos_y_auditoria(self) -> None:
        report = self.execute(r"""
$original = """ + NEW + r""";
$denials = [];
try { $store->append($original, 'fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', false); }
catch (\DomainException $e) { $denials[] = 'unauthorized'; }
try { $store->append($original, 'fixture:tenant/dos', 'fixture:company/uno', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $denials[] = 'tenant'; }
try { $store->append($original, 'fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/other', true); }
catch (\DomainException $e) { $denials[] = 'actor'; }
$store->append($original, 'fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', true);
$review = $original->requestState(G::REVIEW, 'fixture:actor/reviewer', true);
$store->append($review, 'fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/reviewer', true);
try { $store->history('fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', false); }
catch (\DomainException $e) { $denials[] = 'read'; }
$history = $store->history('fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', true);
echo json_encode([$denials, count($history), $history[1]['prior_id'] === $original->id(),
    $history[1]['action'], (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_governance_journal')]);
""")
        self.assertEqual(
            [["unauthorized", "tenant", "actor", "read"], 2, True, "CAMBIO_EXPLICITO", 3], report,
        )
        for needle in ("transactional(", "FOR UPDATE", "hash_equals(", "bool $authorized"):
            self.assertIn(needle, REPOSITORY.read_text())

    def test_sin_datos_reales(self) -> None:
        results = self.execute(r"""
$bad = [
 ['someone@example.com', 'fixture:company/uno', 'fixture:governance/acta', 'acta'],
 ['fixture:tenant/uno', 'Empresa SAS', 'fixture:governance/acta', 'acta'],
 ['fixture:tenant/uno', 'fixture:company/uno', 'fixture:governance/doc3001234567', 'acta'],
 ['fixture:tenant/uno', 'fixture:company/uno', 'https://example.com', 'acta'],
 ['fixture:tenant/uno', 'fixture:company/uno', 'fixture:governance/acta', 'personal'],
];
$outcomes = [];
foreach ($bad as $parts) {
  try {
    $entry = G::register(...[...$parts, 'fixture:actor/owner']);
    $store->append($entry, $parts[0], $parts[1], 'fixture:actor/owner', true);
    $outcomes[] = false;
  } catch (\DomainException $e) { $outcomes[] = true; }
}
echo json_encode([$outcomes, (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_governance_entry')]);
""")
        self.assertEqual([[True] * 5, 0], results)
        self.assertNotRegex(REPOSITORY.read_text(), r"(?i)(curl_|httpclient|file_get_contents\()")
        up = MIGRATION.read_text().split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(2, len(re.findall(r"\bCREATE TABLE\b", up)))
        self.assertNotRegex(up, r"\b(DROP TABLE|DELETE FROM|TRUNCATE|ALTER TABLE)\b")

    def test_estados_explicitos(self) -> None:
        report = self.execute(r"""
$entry = """ + NEW + r""";
$blocked = [];
try { $entry->requestState(G::REVIEW, 'fixture:actor/owner', false); }
catch (\DomainException $e) { $blocked[] = 'human'; }
try { $entry->requestState('CERRADO', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $blocked[] = 'closed'; }
$store->append($entry, $entry->tenant(), $entry->company(), $entry->author(), true);
$next = $entry->requestState(G::REVIEW, 'fixture:actor/owner', true);
$store->append($next, $next->tenant(), $next->company(), $next->author(), true);
try { $store->append($entry, $entry->tenant(), $entry->company(), $entry->author(), true); }
catch (\DomainException $e) { $blocked[] = 'replayed'; }
$before = count($store->history('fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', true));
$conn->executeStatement('UPDATE condor_governance_entry SET fingerprint = ? WHERE entry_id = ?',
    [str_repeat('0', 64), $next->id()]);
try { $store->history('fixture:tenant/uno', 'fixture:company/uno', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $blocked[] = 'tampered'; }
echo json_encode([$blocked, $entry->state(), $next->state(), $before]);
""")
        self.assertEqual(
            [["human", "closed", "replayed", "tampered"], "PENDIENTE", "REQUIERE_REVISION", 2], report,
        )
        down = MIGRATION.read_text().split("public function down", 1)[1]
        self.assertIn("SELECT COUNT(*) FROM condor_governance_entry", down)
        self.assertIn("SELECT COUNT(*) FROM condor_governance_journal", down)
        self.assertIn("$this->abortIf(", down)
        self.assertNotIn("'CERRADO'", ENTITY.read_text())


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""M13 #1050: real PHP/DBAL synthetic isolation, access, audit and state acceptance."""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Quality/Entity/QualityRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/QualityRecordRepository.php"
MIGRATION = ROOT / "migrations/Version20261106120000.php"

SQLITE = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement('CREATE TABLE condor_quality_record (
    id VARCHAR(26) PRIMARY KEY, tenant_ref VARCHAR(80), company_ref VARCHAR(80),
    quality_ref VARCHAR(80), category VARCHAR(20), state VARCHAR(20), version INTEGER,
    previous_id VARCHAR(26), previous_state VARCHAR(20), audit_action VARCHAR(20),
    actor_ref VARCHAR(80), occurred_at DATETIME, fingerprint VARCHAR(64),
    UNIQUE (tenant_ref, company_ref, quality_ref, version))');
$conn->executeStatement('CREATE TABLE condor_quality_audit (
    event_id CHAR(32) PRIMARY KEY, tenant_ref VARCHAR(80), company_ref VARCHAR(80),
    quality_ref VARCHAR(80), actor_ref VARCHAR(80), event VARCHAR(40), created_at DATETIME)');
$repo = new \App\Infrastructure\Persistence\QualityRecordRepository($conn);
"""

OPEN = r"""R::open(
    'fixture:tenant/alpha', 'fixture:company/main', 'fixture:quality/alpha',
    'no_conformidad', 'fixture:actor/owner'
)"""


class ModuloTests(unittest.TestCase):
    def php(self, source: str) -> object:
        self.assertTrue((ROOT / "vendor/autoload.php").exists(), "Se requiere composer install")
        self.assertIsNotNone(shutil.which("php"), "Se requiere PHP CLI")
        result = subprocess.run(
            ["php", "-r", "require $argv[1]; " + SQLITE + source,
             str(ROOT / "vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, timeout=35, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        got = self.php(r"""
use App\Domain\Quality\Entity\QualityRecord as R;
$a = """ + OPEN + r""";
$b = R::open('fixture:tenant/beta', 'fixture:company/main',
    'fixture:quality/alpha', 'no_conformidad', 'fixture:actor/owner');
$c = R::open('fixture:tenant/alpha', 'fixture:company/other',
    'fixture:quality/alpha', 'no_conformidad', 'fixture:actor/owner');
foreach ([[$a, 'alpha', 'main'], [$b, 'beta', 'main'], [$c, 'alpha', 'other']] as $item) {
    $repo->append($item[0], 'fixture:tenant/'.$item[1],
        'fixture:company/'.$item[2], 'fixture:actor/owner', true);
}
$one = $repo->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
$two = $repo->forCompany('fixture:tenant/beta', 'fixture:company/main', 'fixture:actor/owner', true);
$three = $repo->forCompany('fixture:tenant/alpha', 'fixture:company/other', 'fixture:actor/owner', true);
echo json_encode([count($one), count($two), count($three),
    $one[0]['id'] === $a->id(), $two[0]['id'] === $b->id(),
    $three[0]['id'] === $c->id()]);
""")
        self.assertEqual([1, 1, 1, True, True, True], got)
        self.assertIn("WHERE tenant_ref = ? AND company_ref = ?", REPOSITORY.read_text())
        self.assertIn("UNIQUE INDEX uniq_quality_scope_version", MIGRATION.read_text())

    def test_permisos_y_auditoria(self) -> None:
        got = self.php(r"""
use App\Domain\Quality\Entity\QualityRecord as R;
$a = """ + OPEN + r""";
$denied = []; 
try { $repo->append($a, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', false); }
catch (\DomainException $e) { $denied[] = 'unauthorized'; }
try { $repo->append($a, 'fixture:tenant/beta', 'fixture:company/main', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $denied[] = 'tenant'; }
try { $repo->append($a, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/other', true); }
catch (\DomainException $e) { $denied[] = 'actor'; }
$repo->append($a, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
$b = $a->transition(R::REVIEW, 'fixture:actor/reviewer', true);
$repo->append($b, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/reviewer', true);
try { $repo->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', false); }
catch (\DomainException $e) { $denied[] = 'read'; }
$rows = $repo->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
try { $repo->append($a, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $denied[] = 'replay'; }
echo json_encode([$denied, count($rows), $rows[1]['previous_id'] === $a->id(),
    $rows[1]['actor_ref'], $rows[1]['audit_action'],
    (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_quality_audit')]);
""")
        self.assertEqual(
            [["unauthorized", "tenant", "actor", "read", "replay"],
             2, True, "fixture:actor/reviewer", "CAMBIO_EXPLICITO", 3], got,
        )
        src = REPOSITORY.read_text()
        for key in ("bool $authorized", "transactional(", "lockClause()", "hash_equals("):
            self.assertIn(key, src)

    def test_sin_datos_reales(self) -> None:
        got = self.php(r"""
use App\Domain\Quality\Entity\QualityRecord as R;
$bad = [
    ['someone@example.com', 'fixture:company/main', 'fixture:quality/alpha', 'no_conformidad'],
    ['fixture:tenant/alpha', 'Empresa SAS', 'fixture:quality/alpha', 'no_conformidad'],
    ['fixture:tenant/alpha', 'fixture:company/main', 'fixture:quality/3115551212', 'no_conformidad'],
    ['fixture:tenant/alpha', 'fixture:company/main', 'https://example.com', 'no_conformidad'],
    ['fixture:tenant/alpha', 'fixture:company/main', 'fixture:quality/alpha', 'personal'],
];
$results = [];
foreach ($bad as $item) {
    try { $repo->append(R::open(...[...$item, 'fixture:actor/owner']),
        $item[0], $item[1], 'fixture:actor/owner', true); $results[] = false; }
    catch (\DomainException $e) { $results[] = true; }
}
echo json_encode([$results, (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_quality_record')]);
""")
        self.assertEqual([[True] * 5, 0], got)
        self.assertNotIn("#[Route", ENTITY.read_text())
        self.assertNotRegex(REPOSITORY.read_text(), r"(?i)(curl_|file_get_contents\(|httpclient)")
        up = MIGRATION.read_text().split("public function up", 1)[1].split("public function down", 1)[0]
        self.assertEqual(2, len(re.findall(r"\bCREATE TABLE\b", up)))
        self.assertNotRegex(up, r"\b(DROP TABLE|DELETE FROM|TRUNCATE|ALTER TABLE)\b")

    def test_estados_explicitos(self) -> None:
        got = self.php(r"""
use App\Domain\Quality\Entity\QualityRecord as R;
$a = """ + OPEN + r""";
$denied = [];
try { $a->transition(R::REVIEW, 'fixture:actor/owner', false); }
catch (\DomainException $e) { $denied[] = 'human'; }
try { $a->transition('CERRADO', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $denied[] = 'closed'; }
$repo->append($a, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
$b = $a->transition(R::REVIEW, 'fixture:actor/owner', true);
$repo->append($b, 'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true);
try { $repo->append($a->transition(R::REVIEW, 'fixture:actor/owner', true),
    'fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $denied[] = 'stale'; }
$before = count($repo->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true));
$conn->executeStatement('UPDATE condor_quality_record SET fingerprint = ? WHERE id = ?',
    [str_repeat('0', 64), $b->id()]);
try { $repo->forCompany('fixture:tenant/alpha', 'fixture:company/main', 'fixture:actor/owner', true); }
catch (\DomainException $e) { $denied[] = 'integrity'; }
echo json_encode([$denied, $a->state(), $b->state(), $before]);
""")
        self.assertEqual(
            [["human", "closed", "stale", "integrity"], "PENDIENTE", "REQUIERE_REVISION", 2],
            got,
        )
        down = MIGRATION.read_text().split("public function down", 1)[1]
        self.assertIn("SELECT COUNT(*) FROM condor_quality_record", down)
        self.assertIn("SELECT COUNT(*) FROM condor_quality_audit", down)
        self.assertIn("$this->abortIf(", down)
        self.assertNotIn("CERRADO", ENTITY.read_text())


if __name__ == "__main__":
    unittest.main()

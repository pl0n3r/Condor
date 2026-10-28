#!/usr/bin/env python3
"""#1044 M08: executable synthetic-only documentary persistence acceptance."""
from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
RECORD = ROOT / 'src/Domain/Documents/Entity/DocumentRecord.php'
REPOSITORY = ROOT / 'src/Infrastructure/Persistence/DocumentRecordRepository.php'
MIGRATION = ROOT / 'migrations/Version20261104120000.php'

# Real PHP domain execution on every runner; with Composer + pdo_sqlite the
# same four AC tests additionally exercise actual DBAL insert/read transactions.
BOOT = r"""
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    eval('namespace App\\Shared\\Id; final class UlidFactory { public static function new(): string { static $n = 0; return str_pad((string) ++$n, 26, "0", STR_PAD_LEFT); } }');
    require 'src/Domain/Documents/Entity/DocumentRecord.php';
}
use App\Domain\Documents\Entity\DocumentRecord as R;
"""

SQLITE = r"""
$conn = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement('CREATE TABLE condor_document_record (
    id VARCHAR(26) PRIMARY KEY, tenant_ref VARCHAR(96), legal_entity_ref VARCHAR(96),
    record_ref VARCHAR(96), version_ref VARCHAR(96), classification VARCHAR(16),
    state VARCHAR(24), version INTEGER, previous_id VARCHAR(26), actor_ref VARCHAR(96),
    audit_action VARCHAR(24), occurred_at DATETIME, fingerprint VARCHAR(64),
    UNIQUE (tenant_ref, legal_entity_ref, record_ref, version))');
$conn->executeStatement('CREATE TABLE condor_document_audit (
    audit_ref VARCHAR(26) PRIMARY KEY, tenant_ref VARCHAR(96), legal_entity_ref VARCHAR(96),
    record_ref VARCHAR(96), version INTEGER, actor_ref VARCHAR(96),
    action VARCHAR(24), occurred_at DATETIME)');
$repo = new \App\Infrastructure\Persistence\DocumentRecordRepository($conn);
"""

OPEN = r"""R::open('fixture:tenant/alpha', 'fixture:legal-entity/main',
    'fixture:dossier/sample', 'fixture:version/first', 'INTERNA',
    'fixture:actor/owner')"""


class ModuloTests(unittest.TestCase):
    def php(self, code: str, database: bool = False) -> object:
        script = BOOT + (SQLITE if database else '') + code
        result = subprocess.run(
            ['php', '-d', 'display_errors=stderr', '-r', script], cwd=ROOT,
            text=True, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr + '\n' + result.stdout)
        return json.loads(result.stdout)

    @staticmethod
    def can_db() -> bool:
        if not (ROOT / 'vendor/autoload.php').is_file():
            return False
        result = subprocess.run(['php', '-r', 'echo extension_loaded("pdo_sqlite") ? "yes" : "no";'],
                                text=True, capture_output=True, timeout=10)
        return result.returncode == 0 and result.stdout == 'yes'

    def test_aislamiento_por_empresa(self) -> None:
        result = self.php(r"""
$a = """ + OPEN + r""";
$b = R::open('fixture:tenant/beta', 'fixture:legal-entity/main',
    'fixture:dossier/sample', 'fixture:version/first', 'INTERNA', 'fixture:actor/owner');
$c = R::open('fixture:tenant/alpha', 'fixture:legal-entity/other',
    'fixture:dossier/sample', 'fixture:version/first', 'INTERNA', 'fixture:actor/owner');
echo json_encode([$a->tenantRef(), $b->tenantRef(), $c->legalEntityRef(),
    count(array_unique([$a->snapshot()['fingerprint'], $b->snapshot()['fingerprint'],
    $c->snapshot()['fingerprint']]))]);
""")
        self.assertEqual(['fixture:tenant/alpha', 'fixture:tenant/beta',
                          'fixture:legal-entity/other', 3], result)
        self.assertIn('WHERE tenant_ref = ? AND legal_entity_ref = ?', REPOSITORY.read_text())
        if self.can_db():
            self.assertEqual([1, 1, 1], self.php(r"""
$a = """ + OPEN + r""";
$b = R::open('fixture:tenant/beta', 'fixture:legal-entity/main',
    'fixture:dossier/sample', 'fixture:version/first', 'INTERNA', 'fixture:actor/owner');
$c = R::open('fixture:tenant/alpha', 'fixture:legal-entity/other',
    'fixture:dossier/sample', 'fixture:version/first', 'INTERNA', 'fixture:actor/owner');
foreach ([$a,$b,$c] as $r) $repo->append($r, $r->tenantRef(), $r->legalEntityRef(),
    $r->actorRef(), 'editor', true);
echo json_encode([count($repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/main',
    'fixture:actor/auditor', 'auditor', true)),
    count($repo->forCompany('fixture:tenant/beta', 'fixture:legal-entity/main',
    'fixture:actor/auditor', 'auditor', true)),
    count($repo->forCompany('fixture:tenant/alpha', 'fixture:legal-entity/other',
    'fixture:actor/auditor', 'auditor', true))]);
""", database=True))

    def test_permisos_y_auditoria(self) -> None:
        source = REPOSITORY.read_text()
        for required in ('bool $authorized', 'transactional(', 'condor_document_audit',
                         "'LECTURA'", "'editor'", "'revisor'", 'hash_equals(',
                         'WHERE tenant_ref = ? AND legal_entity_ref = ?'):
            self.assertIn(required, source)
        self.assertNotIn('DELETE FROM condor_document_record', source)
        if self.can_db():
            self.assertEqual([True] * 4 + [2], self.php(r"""
$a = """ + OPEN + r""";
$denied = []; foreach ([['editor', false], ['unknown', true], ['auditor', true]] as $v) {
    try { $repo->append($a, $a->tenantRef(), $a->legalEntityRef(),
        $a->actorRef(), $v[0], $v[1]); $denied[] = false; }
    catch (\DomainException $e) { $denied[] = true; }
}
$repo->append($a, $a->tenantRef(), $a->legalEntityRef(), $a->actorRef(), 'editor', true);
try { $repo->forCompany($a->tenantRef(), $a->legalEntityRef(),
    'fixture:actor/auditor', 'auditor', false); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
$repo->forCompany($a->tenantRef(), $a->legalEntityRef(),
    'fixture:actor/auditor', 'auditor', true);
echo json_encode([...$denied, (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_document_audit')]);
""", database=True))

    def test_sin_datos_reales(self) -> None:
        self.assertEqual([True] * 6, self.php(r"""
$values = ['someone@example.test', '+573001234567', '900123456',
    'fixture:dossier/12345678', 'ACME SAS', 'fixture:actor/123456789'];
$result = [];
foreach ($values as $i => $value) {
    try {
        $args = ['fixture:tenant/alpha', 'fixture:legal-entity/main',
            'fixture:dossier/sample', 'fixture:version/first', 'INTERNA',
            'fixture:actor/owner'];
        $args[[2,5,0,2,1,5][$i]] = $value;
        R::open(...$args); $result[] = false;
    } catch (\DomainException $e) { $result[] = true; }
}
echo json_encode($result);
"""))
        self.assertNotIn('#[Route', RECORD.read_text())
        self.assertNotRegex(RECORD.read_text(), r'(?i)(curl_|httpclient|file_get_contents\()')
        for path in [RECORD, REPOSITORY, MIGRATION]:
            process = subprocess.run(['php', '-l', str(path)], cwd=ROOT,
                                     capture_output=True, text=True, timeout=15)
            self.assertEqual(0, process.returncode, process.stdout + process.stderr)

    def test_estados_explicitos(self) -> None:
        self.assertEqual([True, True, True, 'BORRADOR', 'REQUIERE_REVISION', 2], self.php(r"""
$a = """ + OPEN + r""";
$denied = [];
try { $a->sendToReview('fixture:actor/reviewer', false); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
try { $a->sendToReview('fixture:actor/owner', true); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
$b = $a->sendToReview('fixture:actor/reviewer', true);
try { $b->sendToReview('fixture:actor/other', true); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
echo json_encode([...$denied, $a->state(), $b->state(), $b->version()]);
"""))
        migration = MIGRATION.read_text()
        up = migration.split('public function up', 1)[1].split('public function down', 1)[0]
        self.assertEqual(2, len(re.findall(r'\bCREATE TABLE\b', up)))
        self.assertNotRegex(up, r'\b(DROP TABLE|DELETE FROM|TRUNCATE|ALTER TABLE)\b')
        self.assertIn('UNIQUE INDEX uniq_document_company_version', up)
        self.assertIn('ON DELETE RESTRICT', up)
        down = migration.split('public function down', 1)[1]
        self.assertIn('SELECT COUNT(*) FROM condor_document_audit', down)
        self.assertIn('SELECT COUNT(*) FROM condor_document_record', down)
        self.assertIn('$this->abortIf(', down)
        if self.can_db():
            self.assertEqual([True, 'REQUIERE_REVISION', 2], self.php(r"""
$a = """ + OPEN + r""";
$repo->append($a, $a->tenantRef(), $a->legalEntityRef(), $a->actorRef(), 'editor', true);
$b = $a->sendToReview('fixture:actor/reviewer', true);
$repo->append($b, $b->tenantRef(), $b->legalEntityRef(), $b->actorRef(), 'revisor', true);
$replayDenied = false;
try { $repo->append($a, $a->tenantRef(), $a->legalEntityRef(), $a->actorRef(), 'editor', true); }
catch (\Throwable $e) { $replayDenied = true; }
$rows = $repo->forCompany($a->tenantRef(), $a->legalEntityRef(),
    'fixture:actor/auditor', 'auditor', true);
echo json_encode([$replayDenied, $rows[1]['state'], count($rows)]);
""", database=True))


if __name__ == '__main__':
    unittest.main()

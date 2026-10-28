#!/usr/bin/env python3
"""Executable acceptance of synthetic-only M08 events and optional DBAL SQLite."""
from __future__ import annotations

import json
import re
from pathlib import Path
from subprocess import run
from unittest import TestCase

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'src/Domain/Documents/Entity/DocumentRecord.php'
ADAPTER = ROOT / 'src/Infrastructure/Persistence/DocumentRecordRepository.php'
MIGRATION = ROOT / 'migrations/Version20261104120000.php'

EXERCISE = r"""
if (is_file('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    eval('namespace App\\Shared\\Id; final class UlidFactory { public static function new(): string { static $n = 0; return str_pad((string) ++$n, 26, "0", STR_PAD_LEFT); } }');
    require 'src/Domain/Documents/Entity/DocumentRecord.php';
}
use App\Domain\Documents\Entity\DocumentRecord as D;
function rejects(callable $do): bool {
    try { $do(); return false; } catch (\DomainException $e) { return true; }
}
$record = static fn(string $tenant, string $company) => D::open(
    $tenant, $company, 'fixture:dossier/demo', 'fixture:version/one',
    'RESERVADA', 'fixture:actor/editor');
$a = $record('fixture:tenant/alpha', 'fixture:legal-entity/main');
$b = $record('fixture:tenant/beta', 'fixture:legal-entity/main');
$c = $record('fixture:tenant/alpha', 'fixture:legal-entity/other');
$distinct = count(array_unique(array_map(
    static fn(D $d): string => $d->snapshot()['fingerprint'], [$a, $b, $c])));

$invalid = [
    ['someone@example.test', 2], ['+573001234567', 5],
    ['900123456', 0], ['fixture:dossier/12345678', 2],
    ['Empresa Real SAS', 1], ['fixture:actor/123456789', 5],
];
$bad = [];
foreach ($invalid as [$value, $position]) {
    $args = ['fixture:tenant/alpha', 'fixture:legal-entity/main',
        'fixture:dossier/demo', 'fixture:version/one', 'RESERVADA', 'fixture:actor/editor'];
    $args[$position] = $value;
    $bad[] = rejects(static fn(): D => D::open(...$args));
}
$reviewer = 'fixture:actor/reviewer';
$denied = [
    rejects(static fn(): D => $a->sendToReview($reviewer, false)),
    rejects(static fn(): D => $a->sendToReview('fixture:actor/editor', true)),
];
$review = $a->sendToReview($reviewer, true);
$denied[] = rejects(static fn(): D => $review->sendToReview('fixture:actor/other', true));

$db = null;
if (is_file('vendor/autoload.php') && extension_loaded('pdo_sqlite')) {
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
    $blocked = [
        rejects(static fn() => $repo->append($a, $a->tenantRef(),
            $a->legalEntityRef(), $a->actorRef(), 'editor', false)),
        rejects(static fn() => $repo->append($a, $a->tenantRef(),
            $a->legalEntityRef(), $a->actorRef(), 'auditor', true)),
        rejects(static fn() => $repo->append($a, $b->tenantRef(),
            $a->legalEntityRef(), $a->actorRef(), 'editor', true)),
    ];
    foreach ([$a, $b, $c] as $one) {
        $repo->append($one, $one->tenantRef(), $one->legalEntityRef(),
            $one->actorRef(), 'editor', true);
    }
    $repo->append($review, $review->tenantRef(), $review->legalEntityRef(),
        $review->actorRef(), 'revisor', true);
    $scope = [];
    foreach ([['fixture:tenant/alpha', 'fixture:legal-entity/main'],
              ['fixture:tenant/beta', 'fixture:legal-entity/main'],
              ['fixture:tenant/alpha', 'fixture:legal-entity/other']] as [$tenant, $company]) {
        $scope[] = count($repo->forCompany($tenant, $company,
            'fixture:actor/auditor', 'auditor', true));
    }
    $blocked[] = rejects(static fn(): array => $repo->forCompany(
        $a->tenantRef(), $a->legalEntityRef(), $a->actorRef(), 'auditor', false));
    $blocked[] = rejects(static fn() => $repo->append($a, $a->tenantRef(),
        $a->legalEntityRef(), $a->actorRef(), 'editor', true));
    $db = ['scope' => $scope, 'blocked' => $blocked,
        'audit_count' => (int) $conn->fetchOne('SELECT COUNT(*) FROM condor_document_audit')];
}
echo json_encode(['distinct' => $distinct, 'invalid' => $bad,
    'denied' => $denied, 'states' => [$a->state(), $review->state(), $review->version()],
    'db' => $db], JSON_THROW_ON_ERROR);
"""


class ModuloTests(TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        proc = run(['php', '-d', 'display_errors=stderr', '-r', EXERCISE], cwd=ROOT,
                   capture_output=True, text=True, check=False, timeout=35)
        if proc.returncode:
            raise AssertionError(proc.stdout + proc.stderr)
        cls.observed = json.loads(proc.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        self.assertEqual(3, self.observed['distinct'])
        if self.observed['db'] is not None:
            self.assertEqual([2, 1, 1], self.observed['db']['scope'])
        self.assertIn('WHERE tenant_ref = ? AND legal_entity_ref = ?', ADAPTER.read_text())

    def test_permisos_y_auditoria(self) -> None:
        source = ADAPTER.read_text()
        for item in ('bool $authorized', 'transactional(', 'condor_document_audit',
                     "'LECTURA'", "'editor'", "'revisor'", 'hash_equals('):
            self.assertIn(item, source)
        if self.observed['db'] is not None:
            self.assertEqual([True] * 5, self.observed['db']['blocked'])
            self.assertEqual(8, self.observed['db']['audit_count'])

    def test_sin_datos_reales(self) -> None:
        self.assertEqual([True] * 6, self.observed['invalid'])
        self.assertNotIn('#[Route', SRC.read_text())
        self.assertNotRegex(SRC.read_text(), r'(?i)(curl_|httpclient|file_get_contents\()')
        for path in (SRC, ADAPTER, MIGRATION):
            proc = run(['php', '-l', str(path)], cwd=ROOT,
                       capture_output=True, text=True, timeout=15)
            self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)

    def test_estados_explicitos(self) -> None:
        self.assertEqual([True, True, True], self.observed['denied'])
        self.assertEqual(['BORRADOR', 'REQUIERE_REVISION', 2], self.observed['states'])
        source = MIGRATION.read_text()
        up, down = source.split('public function down', 1)
        self.assertEqual(2, len(re.findall(r'\bCREATE TABLE\b', up)))
        self.assertIn('ON DELETE RESTRICT', up)
        self.assertIn('UNIQUE INDEX uniq_document_company_version', up)
        self.assertNotRegex(up, r'\b(DROP TABLE|TRUNCATE|DELETE FROM)\b')
        self.assertIn('$this->abortIf(', down)
        self.assertIn('SELECT COUNT(*) FROM condor_document_audit', down)
        self.assertIn('SELECT COUNT(*) FROM condor_document_record', down)


if __name__ == '__main__':
    from unittest import main
    main()

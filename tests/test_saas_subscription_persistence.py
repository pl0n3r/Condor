#!/usr/bin/env python3
"""AC01..AC04: execute real PHP domain logic with synthetic-only data.

No database, network, payment provider, credentials or real persons are used.
The DBAL repository's scoped SQL and migration are checked as security contracts;
full MariaDB persistence still requires the canonical CI integration gates.
"""
from __future__ import annotations

from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
RECORD = ROOT / "src/Domain/Commercial/Entity/TenantSubscriptionRecord.php"
REPOSITORY = ROOT / "src/Infrastructure/Persistence/TenantSubscriptionRepository.php"
MIGRATION = ROOT / "migrations/Version20261201120000.php"

BOOT = r"""
require 'src/Domain/Commercial/UsageMetric.php';
require 'src/Domain/Commercial/UsageRecord.php';
require 'src/Domain/Commercial/Entity/TenantSubscriptionRecord.php';
use App\Domain\Commercial\UsageMetric;
use App\Domain\Commercial\UsageRecord;
use App\Domain\Commercial\Entity\TenantSubscriptionRecord;
$start = new DateTimeImmutable('2026-01-01T00:00:00Z');
$end = new DateTimeImmutable('2026-02-01T00:00:00Z');
$observed = new DateTimeImmutable('2026-01-15T00:00:00Z');
$recorded = new DateTimeImmutable('2026-01-16T00:00:00Z');
$subscriptionId = '01K00000000000000000000000';
$usage = new UsageRecord('fixture-company-a', UsageMetric::Users, 3, $start, $end, $observed);
"""


class SaasTests(unittest.TestCase):
    def php(self, code: str) -> str:
        result = subprocess.run(
            ["php", "-d", "display_errors=stderr", "-r", BOOT + code],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout.strip()

    def test_aislamiento_por_empresa(self) -> None:
        self.assertEqual("isolated", self.php(r"""
try {
    new TenantSubscriptionRecord(
        'fixture-company-b', $subscriptionId, $usage, 'fixture-event-1',
        'fixture-admin', $recorded
    );
    exit(3);
} catch (DomainException $e) { echo 'isolated'; }
"""))
        repository = REPOSITORY.read_text(encoding="utf-8")
        self.assertIn("WHERE id = ? AND tenant_id = ? FOR UPDATE", repository)
        self.assertIn("WHERE tenant_id = ? AND subscription_id = ?", repository)
        self.assertIn("$record->tenantId() !== $tenantId", repository)

    def test_auditoria_y_permisos(self) -> None:
        self.assertEqual("fixture-admin", self.php(r"""
$entry = new TenantSubscriptionRecord(
    'fixture-company-a', $subscriptionId, $usage, 'fixture-event-1',
    'fixture-admin', $recorded
);
echo $entry->snapshot()['actor_id'];
"""))
        repository = REPOSITORY.read_text(encoding="utf-8")
        self.assertIn("bool $authorized", repository)
        self.assertIn("if (!$authorized", repository)
        self.assertIn("Conflicto de idempotencia", repository)
        self.assertIn("$this->connection->transactional(", repository)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual("blocked", self.php(r"""
try {
    new TenantSubscriptionRecord(
        'persona@example.com', $subscriptionId, $usage,
        'fixture-event-1', 'fixture-admin', $recorded
    );
    exit(4);
} catch (DomainException $e) { echo 'blocked'; }
"""))
        self.assertEqual("blocked", self.php(r"""
try {
    new TenantSubscriptionRecord(
        'fixture-company-a', $subscriptionId, $usage,
        'fixture-event-1', '+573001234567', $recorded
    );
    exit(4);
} catch (DomainException $e) { echo 'blocked'; }
"""))
        self.assertNotIn("payment_gateway", REPOSITORY.read_text(encoding="utf-8").lower())

    def test_fail_closed(self) -> None:
        self.assertEqual("valid", self.php(r"""
$a = new TenantSubscriptionRecord(
    'fixture-company-a', $subscriptionId, $usage,
    'fixture-event-1', 'fixture-admin', $recorded
);
$b = new TenantSubscriptionRecord(
    'fixture-company-a', $subscriptionId, $usage,
    'fixture-event-1', 'fixture-admin', $recorded
);
$left = $a->snapshot();
$right = $b->snapshot();
if ($left !== $right || strlen($left['fingerprint']) !== 64) exit(6);
echo 'valid';
"""))
        self.assertEqual("blocked", self.php(r"""
try {
    new TenantSubscriptionRecord(
        'fixture-company-a', $subscriptionId, $usage, 'fixture-event-1',
        'fixture-admin', new DateTimeImmutable('2025-01-01T00:00:00Z')
    );
    exit(4);
} catch (DomainException $e) { echo 'blocked'; }
"""))
        migration = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("FOREIGN KEY (subscription_id)", migration)
        self.assertIn("PRIMARY KEY (tenant_id, event_key)", migration)
        self.assertIn("IrreversibleMigration", migration)
        for path in (RECORD, REPOSITORY, MIGRATION):
            result = subprocess.run(
                ["php", "-n", "-l", str(path)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

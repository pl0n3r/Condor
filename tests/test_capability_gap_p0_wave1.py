#!/usr/bin/env python3
"""Condor #1488: frontera de escritura del catálogo y aislamiento de tenant."""

from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "src/Application/Catalog/CatalogCapabilityGuard.php"
IMPORTER = ROOT / "src/Application/Catalog/ProductCsvImporter.php"
REGISTRY = ROOT / "docs/architecture/capability-registry.md"

PHP_GUARD_SMOKE = r"""
declare(strict_types=1);
require $argv[1].'/src/Application/Commercial/EntitlementSnapshot.php';
require $argv[1].'/src/Application/Commercial/ExecutionContext.php';
require $argv[1].'/src/Application/Catalog/CatalogCapabilityGuard.php';

use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\ExecutionContext;
use App\Application\Catalog\CatalogCapabilityGuard;

function snapshot(string $tenant, array $caps): EntitlementSnapshot {
    return new EntitlementSnapshot($tenant, 'synthetic', 1, 'commerce', $caps, [], [], [], new DateTimeImmutable('2026-11-05T12:00:00Z'));
}
function denies(callable $fn): bool {
    try { $fn(); } catch (DomainException) { return true; }
    return false;
}

$guard = new CatalogCapabilityGuard();
$approved = new ExecutionContext('tenant-a', snapshot('tenant-a', ['catalog' => true]), 'actor-a');
$guard->assertCanWrite($approved, 'tenant-a');
if (!denies(fn() => $guard->assertCanWrite($approved, 'tenant-b'))) { exit(10); }
$disabled = new ExecutionContext('tenant-a', snapshot('tenant-a', ['catalog' => false]), 'actor-a');
if (!denies(fn() => $guard->assertCanWrite($disabled, 'tenant-a'))) { exit(11); }
$missing = new ExecutionContext('tenant-a', snapshot('tenant-a', []), 'actor-a');
if (!denies(fn() => $guard->assertCanWrite($missing, 'tenant-a'))) { exit(12); }
if (!denies(fn() => new ExecutionContext('tenant-b', snapshot('tenant-a', ['catalog' => true]), 'actor-b'))) { exit(13); }
echo 'OK';
"""


class CapabilityGapP0Wave1Tests(unittest.TestCase):
    def test_guard_pure_enforces_capability_and_tenant(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "Se requiere PHP CLI")
        for file in (GUARD, IMPORTER):
            self.assertTrue(file.is_file())
            lint = subprocess.run([php, "-l", str(file)], capture_output=True, text=True)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        run = subprocess.run([php, "-r", PHP_GUARD_SMOKE, str(ROOT)], capture_output=True, text=True)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        self.assertEqual("OK", run.stdout)

    def test_server_side_guard_runs_before_csv_write(self) -> None:
        src = IMPORTER.read_text(encoding="utf-8")
        self.assertIn("CatalogCapabilityGuard $capabilityGuard", src)
        self.assertIn("SubscriptionEntitlementContextFactory $entitlementContexts", src)
        self.assertIn("EntitlementResolver $entitlementResolver", src)
        self.assertIn("$this->capabilityGuard->assertCanWrite(", src)
        self.assertLess(
            src.index("$this->assertCatalogCapability($tenant, $actor);"),
            src.index("$result = $this->preview("),
        )
        self.assertIn("['tenantId' => $tenant->id()]", src)
        self.assertIn("$this->entityManager->wrapInTransaction(", src)

    def test_registry_keeps_unproven_gaps_visible(self) -> None:
        content = REGISTRY.read_text(encoding="utf-8")
        self.assertIn("GAP-P0-05", content)
        self.assertIn("CatalogCapabilityGuard", content)
        self.assertIn("ProductCsvImporter::commit", content)
        self.assertIn("tests/test_capability_gap_p0_wave1.py", content)
        for name in ("catálogo", "comercio", "CMS", "producción", "compras", "workflows"):
            self.assertIn(name, content)


if __name__ == "__main__":
    unittest.main()

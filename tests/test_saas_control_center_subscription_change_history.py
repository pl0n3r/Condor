#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/php/Http/PlatformOwnerCommercialSubscriptionChangeHistoryTest.php"
HISTORY = ROOT / "src/Application/Commercial/PlatformCommercialSubscriptionChangeHistory.php"
CONTEXT = ROOT / "src/Http/Controller/PlatformOwnerContextController.php"
UI = ROOT / "frontend/admin/PlatformOwnerApp.tsx"
VERSION = ROOT / "config/version.php"


class SaasControlCenterSubscriptionChangeHistoryTests(unittest.TestCase):
    def phpunit(self, pattern: str) -> None:
        runner = ROOT / "vendor/bin/simple-phpunit"
        result = subprocess.run(
            [str(runner), "--filter", pattern, str(PHP_TEST)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        ) if runner.exists() else None
        self.assertIsNotNone(result, "vendor/bin/simple-phpunit no está disponible")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_owner_gets_recent_tenant_scoped_history(self) -> None:
        self.phpunit("testOwnerGetsRecentTenantScopedHistory")
        source = HISTORY.read_text(encoding="utf-8")
        self.assertIn("self::LIMIT", source)
        self.assertIn("['requestedAt' => 'DESC']", source)
        self.assertIn("['tenantId' => $tenantId]", source)

    def test_corrupt_record_fails_closed(self) -> None:
        self.phpunit("testCorruptRecordFailsClosed")
        self.assertIn("$record->toChange()", HISTORY.read_text(encoding="utf-8"))

    def test_payload_is_read_only_and_minimal(self) -> None:
        self.phpunit("testPayloadIsMinimalAndReadOnly")
        source = HISTORY.read_text(encoding="utf-8")
        for forbidden in ("overrides()", "addOns()", "persist(", "flush("):
            self.assertNotIn(forbidden, source)
        self.assertNotIn("#[Route", source)

    def test_empty_and_unknown_tenant_boundaries(self) -> None:
        self.phpunit("testEmptyHistoryAndUnknownTenantBoundaries")
        context = CONTEXT.read_text(encoding="utf-8")
        self.assertLess(
            context.index("$context->tenant($tenantId)"),
            context.index("$changeHistory\n                ->forTenant($tenantId)"),
        )

    def test_owner_ui_renders_read_only_change_history(self) -> None:
        ui = UI.read_text(encoding="utf-8")
        self.assertIn("Historial de cambios", ui)
        self.assertIn("Sin cambios comerciales registrados.", ui)
        self.assertIn("commercial_subscription_changes", ui)
        self.assertIn("Solo lectura", ui)

    def test_release_and_read_only_contract(self) -> None:
        self.assertIn("'version' => '0.1.100'", VERSION.read_text(encoding="utf-8"))
        context = CONTEXT.read_text(encoding="utf-8")
        self.assertIn("methods: ['GET']", context)
        self.assertIn("commercial_subscription_changes", context)
        source = HISTORY.read_text(encoding="utf-8")
        self.assertNotIn("Request", source)
        self.assertNotIn("JsonResponse", source)


if __name__ == "__main__":
    unittest.main()

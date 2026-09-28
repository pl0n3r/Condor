#!/usr/bin/env python3
"""Final acceptance contracts for password recovery without inventing providers."""

from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PasswordRecoveryAcceptanceTests(unittest.TestCase):
    def test_privacy_map_keeps_reset_minimized_without_provider(self) -> None:
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        reset = next(
            treatment
            for treatment in data["treatments"]
            if treatment["id"] == "condor_password_reset"
        )
        self.assertEqual(
            ["user_id", "token_hash", "expires_at", "consumed_at", "revoked_at"],
            reset["fields"],
        )
        self.assertEqual("password_reset_lifecycle", reset["retention"])
        self.assertEqual([], reset["providers"])

    def test_e2e_gateway_is_wired_only_in_test_environment(self) -> None:
        prod_services = (ROOT / "config/services.yaml").read_text(encoding="utf-8")
        test_services = (ROOT / "config/services_test.yaml").read_text(encoding="utf-8")
        gateway = (
            ROOT / "src/Infrastructure/Notification/E2eFileTransactionalEmailGateway.php"
        ).read_text(encoding="utf-8")

        self.assertNotIn("E2eFileTransactionalEmailGateway", prod_services)
        self.assertIn("E2eFileTransactionalEmailGateway", test_services)
        self.assertIn("getenv('APP_ENV') === 'test'", gateway)
        self.assertIn("CONDOR_E2E_MAILBOX_PATH", gateway)
        self.assertIn("sys_get_temp_dir()", gateway)

    def test_ci_executes_real_browser_recovery_with_ephemeral_mailbox(self) -> None:
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        spec = (ROOT / "tests/e2e/password-security.spec.mjs").read_text(
            encoding="utf-8"
        )

        self.assertIn("E2E_RECOVERY_EMAIL: recovery-e2e@example.test", workflow)
        self.assertIn("CONDOR_E2E_MAILBOX_PATH: /tmp/condor-e2e-mailbox.jsonl", workflow)
        self.assertIn("--password-env=E2E_RECOVERY_PASSWORD", workflow)
        self.assertIn('rm -f "$CONDOR_E2E_MAILBOX_PATH"', workflow)
        self.assertIn("waitForResetUrl(recoveryEmail)", spec)
        self.assertIn("recoveryNewPassword", spec)
        self.assertIn("recoveryChangedPassword", spec)
        self.assertIn("No pudimos iniciar sesión con esos datos.", spec)

    def test_operation_runbook_does_not_promote_test_capture_to_provider(self) -> None:
        runbook = (ROOT / "docs/password-recovery-operation.md").read_text(
            encoding="utf-8"
        )
        self.assertIn("providers: []", runbook)
        self.assertIn("no representa ni declara un proveedor externo", runbook)
        self.assertIn("APP_ENV=test", runbook)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UI = ROOT / "frontend/admin/PlatformOwnerApp.tsx"
VERSION = ROOT / "config/version.php"


class SaasControlCenterTrialUiAcceptanceTests(unittest.TestCase):
    def source(self) -> str:
        return UI.read_text(encoding="utf-8")

    def trial_method(self) -> str:
        source = self.source()
        start = source.index("async function startBusinessTrial()")
        end = source.index("  return (", start)
        return source[start:end]

    def test_trial_action_is_only_for_unconfigured_tenant_and_posts_existing_endpoint(self) -> None:
        source = self.source()
        self.assertIn("status === 'not_configured'", source)
        self.assertIn("Iniciar trial Negocio · 14 días", source)
        self.assertIn("/commercial-subscription/trial", self.trial_method())

    def test_trial_request_uses_existing_csrf_and_empty_server_authoritative_payload(self) -> None:
        method = self.trial_method()
        self.assertIn("'X-CSRF-Token': subscriptionToken", method)
        self.assertIn("body: JSON.stringify({})", method)
        for forbidden in ("plan_version_id", "duration_days", "target_state"):
            self.assertNotIn(forbidden, method)

    def test_creation_actions_are_mutually_exclusive_and_fail_closed(self) -> None:
        source = self.source()
        method = self.trial_method()
        create_start = source.index("async function createInitialSubscription()")
        create_end = source.index("async function startBusinessTrial()", create_start)
        create_method = source[create_start:create_end]
        self.assertIn("trialCreation.status === 'saving'", create_method)
        self.assertIn("subscriptionCreation.status === 'saving'", method)
        self.assertIn("commercialCreationInFlight.current", create_method)
        self.assertIn("commercialCreationInFlight.current", method)
        self.assertGreaterEqual(source.count("trialCreation.status === 'saving'"), 4)
        self.assertIn("response.status === 403", method)
        self.assertIn("response.status === 422", method)
        self.assertIn('role="alert"', source)

    def test_success_refreshes_context_and_trial_window_is_rendered(self) -> None:
        source = self.source()
        method = self.trial_method()
        self.assertIn("await loadContext(", method)
        self.assertIn("trial_started_at?: string", source)
        self.assertIn("trial_ends_at?: string", source)
        self.assertIn("subscription.state === 'trialing'", source)
        self.assertIn("Inicio trial", source)
        self.assertIn("Fin trial", source)
        self.assertIn("'version' => '0.1.102'", VERSION.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()

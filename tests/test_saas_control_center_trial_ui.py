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
        self.assertIn("trialCreationErrorMessage(response.status)", method)
        self.assertIn("if (status === 403)", source)
        self.assertIn("if (status === 422)", source)
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


    def test_trial_error_mapping_is_module_level_and_explicit(self) -> None:
        source = self.source()
        component = source.index("export function PlatformOwnerApp")
        helper = source.index("function trialCreationErrorMessage(status: number): string")
        self.assertLess(helper, component)
        body = source[helper:component]
        self.assertIn("if (status === 403)", body)
        self.assertIn("if (status === 422)", body)
        self.assertIn("Tu sesión no puede iniciar este trial.", body)
        self.assertIn("No fue posible iniciar el trial para esta empresa.", body)
        self.assertIn("return 'No fue posible iniciar el trial.';", body)

    def test_trial_method_uses_error_helper_without_nested_ternary(self) -> None:
        method = self.trial_method()
        self.assertIn("trialCreationErrorMessage(response.status)", method)
        self.assertNotIn("response.status === 403", method)
        self.assertNotIn("response.status === 422", method)
        self.assertNotIn("? 'Tu sesión no puede iniciar este trial.'", method)

    def test_trial_regression_contract_is_preserved(self) -> None:
        source = self.source()
        method = self.trial_method()
        self.assertIn("'X-CSRF-Token': subscriptionToken", method)
        self.assertIn("body: JSON.stringify({})", method)
        self.assertIn("subscriptionCreation.status === 'saving'", method)
        self.assertIn("commercialCreationInFlight.current", method)
        self.assertIn("await loadContext(", method)
        self.assertIn("subscription.state === 'trialing'", source)
        self.assertIn("trial_started_at?: string", source)
        self.assertIn("trial_ends_at?: string", source)

    def test_release_is_at_least_0_1_103(self) -> None:
        source = VERSION.read_text(encoding="utf-8")
        marker = "'version' => '"
        start = source.index(marker) + len(marker)
        end = source.index("'", start)
        version = tuple(int(part) for part in source[start:end].split("."))
        self.assertGreaterEqual(version, (0, 1, 103))


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UI = ROOT / "frontend/admin/PlatformOwnerApp.tsx"
VERSION = ROOT / "config/version.php"

CANONICAL_STATES = (
    "trialing",
    "active",
    "past_due",
    "grace_period",
    "suspended",
    "cancelled",
)


class SaasControlCenterSubscriptionStateUiAcceptanceTests(unittest.TestCase):
    def source(self) -> str:
        return UI.read_text(encoding="utf-8")

    def transition_method(self) -> str:
        source = self.source()
        start = source.index("async function transitionSubscriptionState()")
        end = source.index("  return (", start)
        return source[start:end]

    def state_catalog(self) -> str:
        source = self.source()
        start = source.index("const SUBSCRIPTION_STATES = [")
        end = source.index("] as const;", start)
        return source[start:end]

    def test_state_control_is_configured_only_and_does_not_duplicate_transition_matrix(self) -> None:
        source = self.source()
        method = self.transition_method()
        catalog = self.state_catalog()
        self.assertIn("<strong>Cambiar estado</strong>", source)
        configured_anchor = "selected.commercial_subscription.status === 'not_configured' ? ("
        self.assertIn(configured_anchor, source)
        self.assertGreater(
            source.index("<SubscriptionStateTransitionControl"),
            source.index(configured_anchor),
        )
        self.assertEqual(source.count("<SubscriptionStateTransitionControl"), 1)
        self.assertIn("SUBSCRIPTION_STATES.map(", source)
        for state in CANONICAL_STATES:
            self.assertEqual(catalog.count(f"'{state}'"), 1)
        self.assertNotIn("TRANSITIONS", source)
        self.assertNotIn("allowedTransitions", source)
        self.assertNotIn("currentState", catalog)

    def test_state_request_uses_existing_csrf_and_closed_payload(self) -> None:
        method = self.transition_method()
        self.assertIn("/commercial-subscription/state", method)
        self.assertIn("method: 'POST'", method)
        self.assertIn("'X-CSRF-Token': csrfToken", method)
        self.assertIn("csrfToken={subscriptionToken}", self.source())
        self.assertIn("target_state: targetState", method)
        for forbidden in (
            "plan_version_id",
            "duration_days",
            "changed_at",
            "last_changed_at",
            "history",
            "lock_version",
        ):
            self.assertNotIn(forbidden, method)

    def test_state_submit_is_single_flight_and_errors_fail_closed(self) -> None:
        source = self.source()
        method = self.transition_method()
        self.assertIn("inFlight.current", method)
        self.assertIn("transition.status === 'saving'", source)
        self.assertIn("subscriptionStateErrorMessage(response.status)", method)
        self.assertIn("if (status === 403)", source)
        self.assertIn("if (status === 422)", source)
        self.assertIn("catch {", method)
        self.assertIn('role="alert"', source)

    def test_success_refreshes_selected_tenant_context(self) -> None:
        method = self.transition_method()
        self.assertIn("setTargetState('')", method)
        self.assertIn("await onRefresh(tenantId, page, false)", method)

    def test_release_is_0_1_104(self) -> None:
        self.assertIn(
            "'version' => '0.1.104'",
            VERSION.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Contrato estático de instrumentación privacy-safe del configurador."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = (ROOT / "public/configurator-telemetry.js").read_text(encoding="utf-8")
TWIG = (ROOT / "templates/configurator/index.html.twig").read_text(encoding="utf-8")

EVENTS = (
    "start",
    "plan_selected",
    "plan_changed",
    "vertical",
    "addon",
    "abandonment",
    "completion",
    "proposal",
)
FIELDS = ("event", "plan", "vertical", "cycle", "addon", "step")


class PlanConfiguratorTelemetryFrontendTests(unittest.TestCase):
    def test_frontend_emits_only_documented_funnel_events(self) -> None:
        telemetry_position = TWIG.index('/configurator-telemetry.js')
        bundle_position = TWIG.index('/build/configurator.js')
        self.assertLess(telemetry_position, bundle_position)

        self.assertIn("/api/public/configurator/events", SCRIPT)
        for event in EVENTS:
            with self.subTest(event=event):
                self.assertIn(f"'{event}'", SCRIPT)

        self.assertIn(
            "['plan', 'vertical', 'cycle', 'addon', 'step']",
            SCRIPT,
        )
        self.assertIn("originalFetch", SCRIPT)
        self.assertIn("/api/public/configurator/options", SCRIPT)
        self.assertIn("/api/public/configurator/quote", SCRIPT)

    def test_telemetry_is_best_effort(self) -> None:
        self.assertIn("window.fetch = function instrumentedFetch", SCRIPT)
        self.assertIn("return responsePromise", SCRIPT)
        self.assertIn("response.clone()", SCRIPT)
        self.assertIn(".catch(() => {})", SCRIPT)
        self.assertIn("navigator.sendBeacon", SCRIPT)
        self.assertIn("credentials: \'omit\'", SCRIPT)
        self.assertIn("Object.is(state.vertical, vertical)", SCRIPT)
        self.assertIn("Object.is(state.lastOutcome, signature)", SCRIPT)
        self.assertIn("sort((left, right) => left.localeCompare(right))", SCRIPT)
        self.assertIn("'pagehide'", SCRIPT)
        self.assertIn("visibilityState === 'hidden'", SCRIPT)
        self.assertNotIn("throw new Error", SCRIPT)

    def test_frontend_contains_no_pii_prices_or_rules(self) -> None:
        forbidden = (
            "document.cookie",
            "localStorage",
            "sessionStorage",
            "userAgent",
            "fingerprint",
            "email",
            "quantities",
            "monthly_amount",
            "annual_amount",
            "base_amount",
            "addon_amount",
            "total_amount",
            "79900",
            "199900",
            "499900",
            "99900",
        )
        for token in forbidden:
            with self.subTest(token=token):
                self.assertNotIn(token, SCRIPT)

        self.assertNotIn("inventory", SCRIPT)
        self.assertNotIn("manufacturing", SCRIPT)
        self.assertNotIn("legal-cases", SCRIPT)


if __name__ == "__main__":
    unittest.main()

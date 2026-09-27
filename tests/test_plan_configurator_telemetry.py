#!/usr/bin/env python3
"""Contrato macro de telemetría del Plan Configurator #289."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
BACKEND = (ROOT / "src/Http/Controller/PlanConfiguratorTelemetryController.php").read_text(encoding="utf-8")
SIGNAL = (ROOT / "src/Domain/Observability/Entity/FunctionalSignal.php").read_text(encoding="utf-8")
LIMITERS = (ROOT / "config/packages/rate_limiter.yaml").read_text(encoding="utf-8")
FRONTEND = (ROOT / "public/configurator-telemetry.js").read_text(encoding="utf-8")
TWIG = (ROOT / "templates/configurator/index.html.twig").read_text(encoding="utf-8")
DOC = (ROOT / "docs/telemetria-configurador.md").read_text(encoding="utf-8")


class PlanConfiguratorTelemetryTests(unittest.TestCase):
    def test_funnel_telemetry_is_allowlisted_without_pii(self) -> None:
        self.assertIn("CONFIGURATOR_FUNNEL", SIGNAL)
        self.assertIn("/api/public/configurator/events", BACKEND)
        self.assertIn("private const ALLOWED_FIELDS", BACKEND)
        self.assertIn("FunctionalSignal::CONFIGURATOR_FUNNEL", BACKEND)
        self.assertIn("null,", BACKEND)
        self.assertIn("sin cookies", DOC.lower())
        self.assertIn("sin identificadores", DOC.lower())

    def test_telemetry_is_rate_limited_and_best_effort(self) -> None:
        self.assertIn("plan_configurator_events:", LIMITERS)
        self.assertIn("planConfiguratorEventsLimiter", BACKEND)
        self.assertIn("originalFetch", FRONTEND)
        self.assertIn("return responsePromise", FRONTEND)
        self.assertIn("navigator.sendBeacon", FRONTEND)

    def test_frontend_emits_only_documented_funnel_events(self) -> None:
        for event in (
            "start",
            "plan_selected",
            "plan_changed",
            "vertical",
            "addon",
            "abandonment",
            "completion",
            "proposal",
        ):
            with self.subTest(event=event):
                self.assertIn(f"'{event}'", FRONTEND)

        self.assertLess(
            TWIG.index('/configurator-telemetry.js'),
            TWIG.index('/build/configurator.js'),
        )
        for forbidden in (
            "document.cookie",
            "localStorage",
            "sessionStorage",
            "userAgent",
            "quantities",
            "total_amount",
            "email",
        ):
            with self.subTest(token=forbidden):
                self.assertNotIn(forbidden, FRONTEND)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Contrato macro de telemetría del Plan Configurator #289."""
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[1]
def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")
BACKEND = source("src/Http/Controller/PlanConfiguratorTelemetryController.php")
SIGNAL = source("src/Domain/Observability/Entity/FunctionalSignal.php")
LIMITERS = source("config/packages/rate_limiter.yaml")
FRONTEND = source("public/configurator-telemetry.js")
TWIG = source("templates/configurator/index.html.twig")
DOC = source("docs/telemetria-configurador.md")
class PlanConfiguratorTelemetryTests(unittest.TestCase):
    def test_funnel_telemetry_is_allowlisted_without_pii(self) -> None:
        self.assertIn("CONFIGURATOR_FUNNEL", SIGNAL)
        for token in ("/api/public/configurator/events", "private const ALLOWED_FIELDS", "FunctionalSignal::CONFIGURATOR_FUNNEL", "null,"):
            self.assertIn(token, BACKEND)
        for token in ("sin cookies", "sin identificadores"):
            self.assertIn(token, DOC.lower())
    def test_telemetry_is_rate_limited_and_best_effort(self) -> None:
        self.assertIn("plan_configurator_events:", LIMITERS)
        self.assertIn("planConfiguratorEventsLimiter", BACKEND)
        for token in ("originalFetch", "return responsePromise", "navigator.sendBeacon"):
            self.assertIn(token, FRONTEND)
    def test_frontend_emits_only_documented_funnel_events(self) -> None:
        events = ("start", "plan_selected", "plan_changed", "vertical", "addon", "abandonment", "completion", "proposal")
        forbidden = ("document.cookie", "localStorage", "sessionStorage", "userAgent", "quantities", "total_amount", "email")
        for event in events:
            with self.subTest(event=event):
                self.assertIn(f"'{event}'", FRONTEND)
        self.assertLess(TWIG.index("/configurator-telemetry.js"), TWIG.index("/build/configurator.js"))
        for token in forbidden:
            with self.subTest(token=token):
                self.assertNotIn(token, FRONTEND)
if __name__ == "__main__":
    unittest.main()

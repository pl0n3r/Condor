#!/usr/bin/env python3
"""Aceptación ejecutable del backend privacy-safe de #291."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = (ROOT / "src/Http/Controller/PlanConfiguratorTelemetryController.php").read_text(encoding="utf-8")
SIGNAL = (ROOT / "src/Domain/Observability/Entity/FunctionalSignal.php").read_text(encoding="utf-8")
REPORT = (ROOT / "src/Application/Observability/FunctionalSignalReport.php").read_text(encoding="utf-8")
LIMITERS = (ROOT / "config/packages/rate_limiter.yaml").read_text(encoding="utf-8")
TEST_LIMITERS = (ROOT / "config/packages/test/rate_limiter.yaml").read_text(encoding="utf-8")
SERVICES = (ROOT / "config/services.yaml").read_text(encoding="utf-8")
DOC = (ROOT / "docs/telemetria-configurador.md").read_text(encoding="utf-8")


class PlanConfiguratorTelemetryBackendTests(unittest.TestCase):
    def test_funnel_telemetry_is_allowlisted_without_pii(self) -> None:
        self.assertIn("/api/public/configurator/events", CONTROLLER)
        self.assertIn("private const ALLOWED_FIELDS", CONTROLLER)
        for field in ("event", "plan", "vertical", "cycle", "addon", "step"):
            self.assertIn(f"'{field}'", CONTROLLER)

        for forbidden in ("email", "name", "session_id", "user_agent", "quantities"):
            self.assertNotIn(f"'{forbidden}' =>", CONTROLLER)

        self.assertIn("FunctionalSignal::CONFIGURATOR_FUNNEL", SIGNAL)
        self.assertIn(
            "FunctionalSignal::CONFIGURATOR_FUNNEL,\n            null,",
            CONTROLLER,
        )
        self.assertIn("FunctionalSignal::CONFIGURATOR_FUNNEL", REPORT)
        self.assertIn("sin cookies", DOC.lower())
        self.assertIn("sin identificadores", DOC.lower())
        self.assertIn("datos.yml", DOC)

    def test_telemetry_is_rate_limited_and_independent_from_quote(self) -> None:
        self.assertIn("plan_configurator:", LIMITERS)
        self.assertIn("plan_configurator_events:", LIMITERS)
        self.assertIn("plan_configurator:", TEST_LIMITERS)
        self.assertIn("plan_configurator_events:", TEST_LIMITERS)
        self.assertIn("@limiter.plan_configurator_events", SERVICES)
        self.assertIn("@limiter.plan_configurator", SERVICES)
        self.assertIn("planConfiguratorEventsLimiter", CONTROLLER)
        self.assertIn("getClientIp()", CONTROLLER)


if __name__ == "__main__":
    unittest.main()

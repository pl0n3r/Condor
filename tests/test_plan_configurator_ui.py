#!/usr/bin/env python3
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[1]
APP=(ROOT/"frontend/configurator/main.tsx").read_text(encoding="utf-8")
CSS=(ROOT/"public/configurator.css").read_text(encoding="utf-8")
TWIG=(ROOT/"templates/configurator/index.html.twig").read_text(encoding="utf-8")
VITE=(ROOT/"vite.config.ts").read_text(encoding="utf-8")

class PlanConfiguratorUiTests(unittest.TestCase):
    def test_frontend_has_no_price_or_compatibility_source_of_truth(self):
        self.assertNotRegex(APP,r"\b(79900|199900|499900|99900|14900|29900|49900)\b")
        self.assertNotIn("inventory",APP)
        self.assertNotIn("manufacturing",APP)
        self.assertIn("/api/public/configurator/catalog",APP)
        self.assertIn("/api/public/configurator/options",APP)

    def test_selections_use_authoritative_quote_api(self):
        self.assertIn("/api/public/configurator/quote",APP)
        self.assertIn("AbortController",APP)
        self.assertIn("setTimeout",APP)

    def test_responsive_summary_is_accessible(self):
        self.assertIn('aria-live="polite"',APP)
        self.assertIn("position:sticky",CSS)
        self.assertIn("@media(max-width:760px)",CSS)
        self.assertIn(":focus-visible",CSS)

    def test_capability_help_is_presentational_only(self):
        self.assertIn("¿Necesito esto?",APP)
        self.assertIn("<details",APP)
        self.assertNotIn("capability.key ===",APP)

    def test_legal_vertical_hides_irrelevant_options(self):
        self.assertNotIn("legal-cases",APP)
        self.assertIn("options.capabilities.map",APP)

    def test_proposal_state_does_not_invent_price(self):
        self.assertIn("proposal_required",APP)
        self.assertIn("No mostramos un precio inventado",APP)
        self.assertIn('/build/configurator.js',TWIG)
        self.assertIn("configurator: 'frontend/configurator/main.tsx'",VITE)

if __name__=="__main__":
    unittest.main()

"""Regresiones de contrato estático para las API owner-only reutilizadas por #1683."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PlatformPlansUiContractTests(unittest.TestCase):
    def test_only_preexisting_owner_endpoints(self):
        api = (ROOT / "frontend/admin/api.ts").read_text(encoding="utf-8")
        panel = (ROOT / "frontend/admin/PlatformPlansPanel.tsx").read_text(encoding="utf-8")
        self.assertIn("/adminpl0n3r/api/plans/", api)
        self.assertIn("/adminpl0n3r/api/auditoria?type=commercial_plan", api)
        self.assertIn("/adminpl0n3r/planes", api)
        self.assertIn("platformPlanPricesPath(selected.key)", panel)
        self.assertIn("platformPlanLimitsPath(selected.key)", panel)
        self.assertNotIn("stripe", panel.lower())
        self.assertNotIn("mercadopago", panel.lower())

    def test_explicit_null_vs_zero_version_and_csrf(self):
        text = (ROOT / "frontend/admin/PlatformPlansPanel.tsx").read_text(encoding="utf-8")
        self.assertIn("if (value === '') return null;", text)
        self.assertRegex(text, r"Number\(value\) > 2147483647")
        self.assertIn("expected_version: selected.version", text)
        self.assertIn("'X-CSRF-Token': token", text)
        self.assertIn("credentials: 'same-origin'", text)
        self.assertIn("response.status === 422", text)
        self.assertIn("setPreview(true)", text)
        self.assertRegex(text, r"quote_required: draft\.quote")
        self.assertTrue(re.search(r"addons: \[\], verticals: \[\]", text))


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Aceptación ejecutable de Plan Configurator #290."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PRICING_CONTROLLER = (ROOT / "src/Http/Controller/CommercialPricingController.php").read_text(encoding="utf-8")
PRICING_TWIG = (ROOT / "templates/pricing/index.html.twig").read_text(encoding="utf-8")
CONFIG_CONTROLLER = (ROOT / "src/Http/Controller/PlanConfiguratorController.php").read_text(encoding="utf-8")
CONFIG_APP = (ROOT / "frontend/configurator/main.tsx").read_text(encoding="utf-8")
OWNER_CONTEXT = (ROOT / "src/Http/Controller/PlatformOwnerContextController.php").read_text(encoding="utf-8")
OWNER_APP = (ROOT / "frontend/admin/PlatformOwnerApp.tsx").read_text(encoding="utf-8")
SEED_PRICES = ("79900", "799000", "199900", "1999000", "499900", "4999000", "14900", "29900", "49900", "99900")


class PlanConfiguratorConsumerTests(unittest.TestCase):
    def test_pricing_page_reads_canonical_catalog(self) -> None:
        self.assertIn("#[Route('/precios'", PRICING_CONTROLLER)
        self.assertIn("CommercialCatalogReader", PRICING_CONTROLLER)
        self.assertIn("$catalog->current(", PRICING_CONTROLLER)
        self.assertIn("{% for plan in plans %}", PRICING_TWIG)
        self.assertIn("plan.monthly_amount", PRICING_TWIG)
        self.assertIn("plan.annual_amount", PRICING_TWIG)

    def test_pricing_configurator_and_superadmin_share_catalog(self) -> None:
        self.assertIn("$this->catalog->current($at)", CONFIG_CONTROLLER)
        self.assertIn("'version' => $plan['version']", CONFIG_CONTROLLER)
        self.assertIn("CommercialCatalogReader", OWNER_CONTEXT)
        self.assertIn("$catalog->current(", OWNER_CONTEXT)
        self.assertIn("'commercial_catalog' => $commercialCatalog", OWNER_CONTEXT)
        self.assertIn('data-plan-version="{{ plan.version }}"', PRICING_TWIG)
        self.assertIn("commercial_catalog: CommercialPlan[]", OWNER_APP)
        self.assertIn("v{plan.version}", OWNER_APP)

    def test_consumers_do_not_duplicate_prices_or_rules(self) -> None:
        consumers = "\n".join((PRICING_TWIG, CONFIG_APP, OWNER_APP))
        for literal in SEED_PRICES:
            with self.subTest(literal=literal):
                self.assertNotIn(literal, consumers)
        self.assertNotIn("plan.key ==", PRICING_TWIG)
        self.assertNotIn("plan.key ===", CONFIG_APP)
        self.assertNotIn("plan.key ===", OWNER_APP)
        self.assertNotIn("capability.key ===", CONFIG_APP)

    def test_enterprise_remains_unpriced_across_consumers(self) -> None:
        proposal = PRICING_TWIG.split("{% if plan.quote_required %}", 1)[1].split("{% else %}", 1)[0]
        self.assertIn("Solicitar propuesta", proposal)
        self.assertNotIn("monthly_amount", proposal)
        self.assertNotIn("annual_amount", proposal)
        self.assertIn("plan.quote_required", OWNER_APP)
        self.assertIn("Propuesta personalizada", OWNER_APP)
        self.assertIn("proposal_required", CONFIG_APP)
        self.assertIn("No mostramos un precio inventado", CONFIG_APP)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Aceptación estática del API administrativo de tarifas versionadas #1682."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def source(name: str) -> str:
    p = ROOT / name
    assert p.is_file(), f"Falta {name}"
    return p.read_text(encoding="utf-8")


class PlanesPreciosAdminTests(unittest.TestCase):
    def test_owner_only_csrf_audit_and_optimistic_concurrency(self) -> None:
        controller = source("src/Http/Controller/PlatformPlanCatalogController.php")
        service = source("src/Application/Commercial/PlanPriceAdminService.php")
        for needle in ("platformOwner()", "requireManagementCsrf", "isCsrfTokenValid",
                       "/adminpl0n3r/api/plans/{key}/prices",
                       "/adminpl0n3r/api/plans/{key}/prices/cancel"):
            self.assertIn(needle, controller)
        for needle in ("expected_version", "PESSIMISTIC_WRITE", "wrapInTransaction",
                       "PlatformAuditEvent", "'old' => $before", "'new' => $after",
                       "effectiveFrom", "cancelScheduled", "array_diff", "validateRate"):
            self.assertIn(needle, service)

    def test_zero_null_quotes_and_snapshot_not_global_update(self) -> None:
        entity = source("src/Domain/Commercial/Entity/PlanVersion.php")
        service = source("src/Application/Commercial/PlanPriceAdminService.php")
        reader = source("src/Application/Commercial/CommercialCatalogReader.php")
        quote = source("src/Application/Commercial/PlanQuoteService.php")
        for needle in ("priceOptions", "price_options", "reopenAfterCancellation"):
            self.assertIn(needle, entity)
        for needle in ("$amount < 0", "$amount !== null", "quote_required", "monthly_amount"):
            self.assertIn(needle, service)
        self.assertIn("vertical_prices", reader)
        self.assertIn("priceOptions()['addons']", reader)
        self.assertIn("$verticalRate", quote)
        self.assertIn("['annual_amount']", quote)
        pricing = source("templates/pricing/index.html.twig")
        self.assertIn("plan.monthly_amount is null", pricing)
        self.assertIn("plan.annual_amount is null", pricing)
        self.assertIn("Sin tarifa", pricing)
        self.assertIn("$price < 0", quote)
        self.assertNotIn("update AddOn", service)

    def test_migration_and_docs_guard_history_and_payments(self) -> None:
        migration = source("migrations/Version20261106150000.php")
        docs = source("docs/catalogo-planes.md")
        for needle in ("ADD price_options JSON DEFAULT NULL", "IrreversibleMigration"):
            self.assertIn(needle, migration)
        for needle in ("2026-09-27", "2026-10-30", "sin tarifa definida",
                       "Revertir", "PlatformAuditEvent", "Fuera de alcance",
                       "CommercialCatalogReader", "datos", "precios"):
            self.assertIn(needle, docs)
        self.assertNotIn("PaymentGateway", source("src/Application/Commercial/PlanPriceAdminService.php"))


if __name__ == "__main__":
    unittest.main()

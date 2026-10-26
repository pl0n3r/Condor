#!/usr/bin/env python3
"""Condor #932: interfaz de servicios vendibles sobre API existente (sin stock)."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "frontend/admin/ServiceItemsPanel.tsx"
CATALOG = ROOT / "frontend/admin/CatalogManagement.tsx"
API = ROOT / "frontend/admin/api.ts"
SERVICES = ROOT / "src/Http/Controller/ServiceCatalogController.php"
PRODUCTS = ROOT / "src/Http/Controller/CatalogController.php"
PRICING = ROOT / "src/Http/Controller/PricingController.php"


class UiServiciosCatalogoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Crea servicios sin stock y maneja duración, listas, precios y edición real."""
        for file in (PANEL, CATALOG, API, SERVICES, PRODUCTS, PRICING):
            self.assertTrue(file.is_file(), str(file))

        panel = PANEL.read_text(encoding="utf-8")
        catalog = CATALOG.read_text(encoding="utf-8")
        api = API.read_text(encoding="utf-8")
        services = SERVICES.read_text(encoding="utf-8")
        products = PRODUCTS.read_text(encoding="utf-8")
        pricing = PRICING.read_text(encoding="utf-8")

        for value in (
            "catalogProductsPath(branchId)",
            ".replace(/\\/products$/, '/services')",
            "'POST'",
            "name, slug, description:",
            "sku, duration_minutes: duration, price_list_id: draft.priceListId,",
            "amount_minor: amount",
            "minorUnits(draft.price)",
            "durationMinutes(draft.duration)",
            "duration === undefined",
            "Number.isSafeInteger(number) && number <= 525600",
            "setRefresh(value => value + 1)",
            "kind === 'service'",
        ):
            with self.subTest(contract=value):
                self.assertIn(value, panel)

        self.assertIn("services.map(service => service.id)", catalog)
        self.assertIn("!serviceIds.has(product.id)", catalog)
        self.assertIn("<ServiceItemsPanel", catalog)
        self.assertIn("Servicio ·", panel)
        self.assertIn("No generan movimientos de stock.", panel)
        self.assertIn("priceListsPath", api)
        self.assertIn("variantPricePath", api)
        self.assertIn("methods: ['GET']", services)
        self.assertIn("methods: ['POST']", services)
        self.assertIn("'duration_minutes',", services)
        self.assertIn("'amount_minor',", services)
        self.assertIn("methods: ['PATCH']", products)
        self.assertIn("methods: ['PUT']", pricing)

        # No se inventa un endpoint PATCH de servicios (duración sigue no editable).
        self.assertIn("methods: ['POST']", services)
        self.assertIn("duration !== previous.duration_minutes", panel)
        self.assertIn("duration_minutes: duration", panel)
        self.assertNotIn("allow_backorder:", panel)
        self.assertNotIn("inventoryPath(", panel)
        self.assertNotRegex(panel, r"\bstyle\s*=\s*\{")

    def test_permissions_errors_accessibility_and_csrf(self) -> None:
        panel = PANEL.read_text(encoding="utf-8")
        catalog = CATALOG.read_text(encoding="utf-8")
        for guard in (
            "permissions.includes('catalog.view')",
            "permissions.includes('catalog.create')",
            "permissions.includes('catalog.update')",
            "permissions.includes('pricing.view')",
            "permissions.includes('pricing.update')",
            "if (!canView) return null",
            "if (editing ? !canUpdate : !canCreate) return",
            "'X-CSRF-Token': csrfToken",
            "credentials: 'same-origin'",
            "state.status === 'loading'",
            "state.status === 'error'",
            "state.services.length === 0",
            'role="alert"',
            'aria-live="polite"',
            "<label className=",
            '<button type="button"',
            "disabled={busy",
            "response.ok",
        ):
            with self.subTest(contract=guard):
                self.assertIn(guard, panel)

        self.assertIn("No se pudo distinguir productos de servicios.", catalog)
        self.assertIn("catalogProductsPath", catalog)
        self.assertNotRegex(panel, r"\btenant_id\s*:")
        self.assertNotRegex(panel, r"fetch\s*\(\s*['\"][^'\"]*https?://")
        self.assertFalse(re.search(r"\bstyle\s*=\s*\{\{", panel))

    def test_money_and_duration_are_validated_before_writes(self) -> None:
        panel = PANEL.read_text(encoding="utf-8")
        self.assertIn(r"[,.]([0-9]{1,2})", panel)
        self.assertIn("Number.isSafeInteger(total)", panel)
        self.assertIn("Number.isSafeInteger(number)", panel)
        self.assertIn("duration_minutes: duration", panel)
        self.assertIn("amount_minor: amount", panel)
        self.assertIn("'El precio debe ser un valor válido.'", panel)
        self.assertIn("No se pudieron consultar las listas de precios.", panel)


if __name__ == "__main__":
    unittest.main()

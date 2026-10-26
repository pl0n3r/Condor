#!/usr/bin/env python3
"""#870 AC-01: admin customer search and history use authenticated APIs safely."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/CommerceManagement.tsx").read_text(encoding="utf-8")
HISTORY = (ROOT / "src/Http/Controller/CustomerHistoryController.php").read_text(encoding="utf-8")


class UiClientesBusquedaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        # Real branch-scoped backend query: no local fake "new" / "top" segments.
        for value in (
            "Búsqueda y segmentos de clientes",
            "Buscar clientes", "Segmento de clientes", "Aplicar búsqueda",
            "Limpiar búsqueda", "type=\"search\"", "maxLength={120}",
            "new_this_month", "without_purchases_90_days", "top_20_purchases",
            "params.set('term', activeSearch.term)",
            "params.set('segment', activeSearch.segment)",
            "customersPath(branchId) + '/search?' + params.toString()",
            "credentials: 'same-origin'", "signal: controller.signal",
            "AbortController", "searchResult.status === 'loading'",
            "searchResult.status === 'error'", "searchResult.status === 'idle'",
            "No hay clientes que coincidan con los filtros.",
        ):
            with self.subTest(value=value):
                self.assertIn(value, PANEL)
        self.assertNotIn("customers.customers.filter(", PANEL)
        self.assertNotIn("customers.customers.slice(", PANEL)
        self.assertIn("canViewCustomers", PANEL)
        self.assertIn("setSelectedCustomerId(null)", PANEL)

        # Tenant-scoped, authenticated real history, with page controls.
        for value in (
            "'/api/customers/' + encodeURIComponent(selectedCustomerId)",
            "'/history?page=' + historyPage",
            "body.customer_id !== selectedCustomerId",
            "historyResult.status === 'loading'",
            "historyResult.status === 'error'",
            "historyResult.status === 'ready'",
            "Sin compras en esta página.",
            "Última compra:", "Total ", "Página ",
            "historyResult.data.pagination.has_previous",
            "historyResult.data.pagination.has_next",
            "aria-expanded={selectedCustomerId === customer.id}",
            "formatMinorAmount", "Reintentar historial",
        ):
            with self.subTest(value=value):
                self.assertIn(value, PANEL)
        self.assertIn("customers.view", HISTORY)
        self.assertIn("'tenant' => $tenant", HISTORY)
        self.assertIn("['page']", HISTORY)
        self.assertIn("Cache-Control", HISTORY)

    def test_backend_search_is_not_faked(self) -> None:
        # The separate backend #852 must provide a real HTTP endpoint before merge.
        self.assertIn("customersPath(branchId) + '/search?'", PANEL)
        self.assertNotIn("customers.customers.filter(", PANEL)
        self.assertIn("signal: controller.signal", PANEL)
        self.assertIn("status: 'error'", PANEL)
        self.assertIn("La búsqueda avanzada aún no está disponible.", PANEL)


if __name__ == "__main__":
    unittest.main()

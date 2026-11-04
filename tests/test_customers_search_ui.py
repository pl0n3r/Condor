#!/usr/bin/env python3
"""#1512: real, authorized CRM segmentation and order history, without PII in URLs."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/CommerceManagement.tsx").read_text(encoding="utf-8")
CUSTOMERS = (ROOT / "src/Http/Controller/CustomerController.php").read_text(encoding="utf-8")


class UiClientesBusquedaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for value in (
            'Búsqueda y segmentos de clientes', 'Buscar clientes',
            'Segmento de clientes', 'Categoría comercial',
            'Aplicar búsqueda', 'Limpiar búsqueda', 'type="search"',
            'maxLength={120}', 'new_this_month', 'without_purchases_90_days',
            'with_recent_purchases_90_days', 'without_purchases',
            'top_20_purchases', 'by_category',
            "customersPath(branchId) + '/segments'",
            "method: 'POST'", "credentials: 'same-origin'",
            "'X-CSRF-Token': csrfToken",
            "body: JSON.stringify({ term: activeSearch.term, segment: activeSearch.segment,",
            "signal: controller.signal", "AbortController",
            "searchResult.status === 'loading'", "searchResult.status === 'error'",
            "searchResult.status === 'idle'",
            "No hay clientes que coincidan con los filtros.",
        ):
            with self.subTest(value=value):
                self.assertIn(value, PANEL)
        self.assertNotIn('customers.customers.filter(', PANEL)
        self.assertNotIn('customers.customers.slice(', PANEL)

        for value in (
            "customersPath(branchId) + '/history'",
            'customer_id: selectedCustomerId, page: historyPage',
            'canViewOrders', "can('orders.view')",
            'body.customer_id !== selectedCustomerId',
            "historyResult.status === 'loading'", "historyResult.status === 'error'",
            "historyResult.status === 'ready'",
            'Sin compras en esta página.', 'Última compra:', 'Total ',
            'Página ', 'historyResult.data.pagination.has_previous',
            'historyResult.data.pagination.has_next',
            'aria-expanded={selectedCustomerId === customer.id}',
            'formatMinorAmount', 'Reintentar historial',
        ):
            with self.subTest(value=value):
                self.assertIn(value, PANEL)

    def test_backend_search_and_history_are_authorized(self) -> None:
        for value in (
            '/api/v1/branches/{branchId}/customers/segments',
            "name: 'api_customer_segments_query'",
            "'customers.view'", "source.branch = :branch",
            'new_this_month', 'with_recent_purchases_90_days',
            'without_purchases_90_days', 'top_20_purchases', 'by_category',
            '/api/v1/branches/{branchId}/customers/history',
            "'orders.view'", "'customer_id', 'page'",
            "private function branchOrders(Tenant $tenant, Branch $branch)",
        ):
            with self.subTest(value=value):
                self.assertIn(value, CUSTOMERS)
        self.assertIn('requireCsrf($request)', CUSTOMERS)
        self.assertIn('setMaxResults(11)', CUSTOMERS)

        # Negativa cross-tenant y privacidad: no transportar PII en query strings.
        for stale in (
            "params.set('term', activeSearch.term)",
            "params.set('segment', activeSearch.segment)",
            "customersPath(branchId) + '/search?'",
            "'/api/customers/' + encodeURIComponent(selectedCustomerId)",
            "'/history?page=' + historyPage",
            "'/search?' + params.toString()",
        ):
            with self.subTest(stale=stale):
                self.assertNotIn(stale, PANEL)
        self.assertIn('body: JSON.stringify({ customer_id: selectedCustomerId, page: historyPage })', PANEL)
        self.assertIn("body: JSON.stringify({ term: activeSearch.term", PANEL)
        self.assertNotIn('console.log(', CUSTOMERS)


if __name__ == "__main__":
    unittest.main()

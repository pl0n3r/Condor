#!/usr/bin/env python3
"""AC-01 #869: filtros admin y paginación consumen API #851 sin duplicar dominio."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/OrdersManagement.tsx").read_text(encoding="utf-8")
QUERY = (ROOT / "src/Application/Orders/OrderListQuery.php").read_text(encoding="utf-8")


class UiPedidosFiltrosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Estado, fechas, cliente, texto y página llegan a la API con estados de red."""
        self.assertIn("const params = new URLSearchParams({", PANEL)
        self.assertIn("page: String(page)", PANEL)
        self.assertIn("per_page: '20'", PANEL)
        for key, value in (
            ("status", "filters.status"),
            ("from", "filters.from"),
            ("to", "filters.to"),
            ("customer_id", "filters.customerId"),
            ("q", "filters.q.trim()"),
        ):
            with self.subTest(param=key):
                self.assertIn(f"params.set('{key}', {value})", PANEL)
                self.assertIn(f"'{key}'", QUERY)
        self.assertIn("ordersPath(branchId) + '?' + params.toString()", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("pagination: OrdersPagination", PANEL)
        self.assertIn("has_more: boolean", PANEL)
        self.assertIn("ready.pagination.page <= 1", PANEL)
        self.assertIn("disabled={!ready.pagination.has_more}", PANEL)
        self.assertIn("Math.max(1, current - 1)", PANEL)
        self.assertIn("setPage((current) => current + 1)", PANEL)

        for item in (
            'aria-label="Filtros de pedidos"', "Estado del pedido", "Desde",
            "Hasta", "Todos los clientes", "Buscar por ID o cliente",
            'type="date"', 'type="search"', "Aplicar filtros",
            "Limpiar filtros", "No hay pedidos que coincidan",
            'role="alert"', 'role="status"', 'aria-live="polite"',
        ):
            self.assertIn(item, PANEL)

        self.assertIn("setFilters({ ...draftFilters });", PANEL)
        self.assertIn("setPage(1);", PANEL)
        self.assertIn("requestId !== latestRequest.current", PANEL)
        self.assertIn("latestRequest.current += 1", PANEL)
        self.assertIn("if (!canView) {", PANEL)
        self.assertIn("'orders.view'", PANEL)
        self.assertIn("if (state.status !== 'ready')", PANEL)
        self.assertIn("'X-CSRF-Token': csrfToken", PANEL)
        self.assertIn("orderCancelPath(branchId, order.id)", PANEL)
        self.assertIn("orderConsumePath(branchId, order.id)", PANEL)
        self.assertRegex(PANEL, re.compile(r"ready\.orders\.map\(\(order\)", re.MULTILINE))
        self.assertNotIn("tenant_id:", PANEL)

    def test_api_remains_branch_scoped_and_bounds_pagination(self) -> None:
        self.assertIn("o.tenant = :tenant", QUERY)
        self.assertIn("o.legalEntity = :legalEntity", QUERY)
        self.assertIn("o.inventorySource IN (:sources)", QUERY)
        self.assertIn("'per_page' => $perPage", QUERY)
        self.assertIn("'has_more' => $page * $perPage < $total", QUERY)
        # La API conserva límites y validaciones aunque la UI haya cambiado:
        # evita consultas sin límites, rangos inválidos y filtros arbitrarios.
        for guard in (
            "ORDER_LIST_UNKNOWN_FILTER",
            "ORDER_LIST_INVALID_FILTER",
            "ORDER_LIST_INVALID_CUSTOMER",
            "ORDER_LIST_INVALID_SEARCH",
            "ORDER_LIST_INVALID_DATE",
            "ORDER_LIST_INVALID_RANGE",
            "ORDER_LIST_INVALID_PAGINATION",
            "->setFirstResult(($page - 1) * $perPage)",
            "->setMaxResults($perPage)",
            "->addOrderBy('o.id',",
        ):
            with self.subTest(guard=guard):
                self.assertIn(guard, QUERY)
        self.assertIn("ordersPath(branchId) + '?' + params.toString()", PANEL)
        self.assertNotIn("tenant_id:", PANEL)


if __name__ == "__main__":
    unittest.main()

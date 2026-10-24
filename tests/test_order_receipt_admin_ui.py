#!/usr/bin/env python3
"""Contrato offline: acceso seguro al comprobante desde el listado de pedidos."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/OrdersManagement.tsx").read_text(encoding="utf-8")
CONTROLLER = (
    ROOT / "src/Http/Controller/OrderReceiptController.php"
).read_text(encoding="utf-8")


class ComprobanteAdminUiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Cada pedido tiene enlace de comprobante solo para quien puede ver pedidos."""
        self.assertIn("const canView = can('orders.view');", PANEL)
        self.assertIn("if (!canView) {", PANEL)
        self.assertIn("No tienes permiso para consultar pedidos.", PANEL)
        self.assertRegex(
            PANEL,
            re.compile(
                r"ready\.orders\.map\(\(order\).*?"
                r"\{canView && \(\s*<a\b(.*?)>\s*Comprobante\s*</a>",
                re.DOTALL,
            ),
        )
        self.assertIn("/api/orders/", PANEL)
        self.assertIn("encodeURIComponent(order.id)", PANEL)
        self.assertIn('target="_blank"', PANEL)
        self.assertIn('rel="noopener noreferrer"', PANEL)
        self.assertIn("aria-label=", PANEL)
        self.assertIn("className=\"button button-secondary\"", PANEL)

    def test_receipt_is_a_read_only_tenant_scoped_endpoint(self) -> None:
        self.assertIn(
            "#[Route('/api/orders/{id}/receipt', name: 'api_order_receipt', methods: ['GET'])]",
            CONTROLLER,
        )
        self.assertIn("'tenant' => $tenant", CONTROLLER)
        self.assertIn("'orders.view'", CONTROLLER)
        self.assertIn("throw new NotFoundHttpException", CONTROLLER)
        self.assertIn("Cache-Control", CONTROLLER)

    def test_permission_denial_precedes_any_order_render(self) -> None:
        self.assertLess(
            PANEL.index("if (!canView) {"),
            PANEL.index("ready.orders.map((order)"),
        )
        self.assertNotIn("window.open(", PANEL)


if __name__ == "__main__":
    unittest.main()

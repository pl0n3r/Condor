#!/usr/bin/env python3
"""Condor #927: UI acceptance against the real existing order HTTP contracts.

Static assertions intentionally bind the frontend to the backend's routed
methods, supported payloads and domain transition policy; no live data/HTTP.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UI = (ROOT / "frontend/admin/OrderLifecyclePanel.tsx").read_text(encoding="utf-8")
LIST = (ROOT / "frontend/admin/OrdersManagement.tsx").read_text(encoding="utf-8")
API = (ROOT / "frontend/admin/api.ts").read_text(encoding="utf-8")
CONTROLLERS = {
    key: (ROOT / ("src/Http/Controller/" + filename)).read_text(encoding="utf-8")
    for key, filename in (
        ("order", "OrderController.php"),
        ("payment", "OrderPaymentController.php"),
        ("refund", "OrderRefundController.php"),
        ("delivery", "OrderFulfillmentController.php"),
    )
}
MACHINE = (ROOT / "src/Domain/Orders/OrderStateMachine.php").read_text(encoding="utf-8")
DELIVERY = (ROOT / "src/Application/Orders/OrderFulfillmentService.php").read_text(encoding="utf-8")


class UiCicloPedidoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """3 dimensions, permitted transitions, server balance, empty/error, refund, delivery."""
        self.assertIn("import { OrderLifecyclePanel }", LIST)
        self.assertIn("<OrderLifecyclePanel", LIST)
        self.assertIn("canUpdate={canUpdate}", LIST)
        self.assertIn("csrfToken={csrfToken}", LIST)
        self.assertIn("onChanged={loadOrders}", LIST)
        self.assertIn("cancelPath={orderCancelPath(branchId, order.id)}", LIST)
        self.assertIn("consumePath={orderConsumePath(branchId, order.id)}", LIST)
        self.assertIn("export function ordersPath(branchId: string)", API)

        # Displayed statuses are independent; do not incorrectly equate delivery to stock.
        for dimension in ("order.order_status", "order.payment_status",
                          "order.fulfillment_status", "ready.payment.payment_status"):
            self.assertIn(dimension, UI)
        self.assertIn("Estado del pedido", UI)
        self.assertIn("Estado del pago", UI)
        self.assertIn("Estado del inventario", UI)
        self.assertIn("order.order_status === 'open' && order.fulfillment_status === 'reserved'", UI)
        self.assertIn("orderCanConsume = orderCanCancel", UI)
        self.assertIn("['cancelled']", MACHINE)
        self.assertIn("['released', 'consumed']", MACHINE)
        self.assertIn("['paid']", MACHINE)

        # Server is authoritative: balance must be GET from the ledger, not client subtraction.
        self.assertIn("paymentPath = root + '/payments'", UI)
        self.assertIn("deliveryPath = root + '/delivery'", UI)
        self.assertIn("credentials: 'same-origin'", UI)
        self.assertIn("Promise.all([", UI)
        self.assertIn("paymentBody.payment", UI)
        self.assertIn("ready.payment.pending_minor", UI)
        self.assertIn("ready.payment.paid_minor", UI)
        self.assertNotIn("order.total_amount_minor -", UI)
        self.assertIn("setRemote({ status: 'loading' })", UI)
        self.assertIn("status: 'error'", UI)
        self.assertIn("Este pedido todavía no tiene pagos registrados.", UI)

        # All mutations use actual routes, tenant-independent ULID, CSRF and idempotency.
        self.assertIn("ordersPath(branchId) + '/' + encodeURIComponent(order.id)", UI)
        self.assertIn("'X-CSRF-Token': csrfToken", UI)
        self.assertIn("amount_minor: amountMinor, method, idempotency_key: key", UI)
        self.assertIn("payment_key: reference, amount_minor: amountMinor, reason", UI)
        self.assertIn("idempotency_key: key", UI)
        self.assertIn("paymentAttempt.current", UI)
        self.assertIn("refundAttempt.current", UI)
        self.assertIn("payment_key", CONTROLLERS["refund"])
        self.assertIn("'amount_minor', 'method', 'idempotency_key'", CONTROLLERS["payment"])
        self.assertIn("methods: ['POST']", CONTROLLERS["refund"])
        self.assertIn("methods: ['GET']", CONTROLLERS["payment"])
        self.assertIn("methods: ['POST']", CONTROLLERS["payment"])
        self.assertIn("orders/{orderId}/cancel", CONTROLLERS["order"])
        self.assertIn("orders/{orderId}/consume", CONTROLLERS["order"])

        # No fake generic state endpoint or client-supplied tenant; delivery is distinct.
        self.assertNotIn("'/state'", UI)
        self.assertNotIn("tenant_id:", UI)
        for method in ("shipping", "pickup", "none"):
            self.assertIn(method, UI)
            self.assertIn(method, DELIVERY)
        for transition in ("shipped", "delivered", "ready", "collected", "completed"):
            self.assertIn(transition, UI)
        self.assertIn("deliveryPath + '/transition'", UI)
        self.assertIn("tracking_number", CONTROLLERS["delivery"])
        self.assertIn("order.order_status === 'open'", UI)
        self.assertIn("DELIVERY_NEXT[delivery.method]?.[delivery.status]", UI)
        self.assertIn("reason", CONTROLLERS["refund"])
        for reason in ("customer_return", "payment_correction", "order_cancelled", "other"):
            self.assertIn(reason, UI)

        # Read-only access; HTML native controls and accessible async feedback.
        self.assertIn("canUpdate &&", UI)
        self.assertIn('role="alert"', UI)
        self.assertIn('aria-live="polite"', UI)
        self.assertIn("Reintentar carga", UI)
        self.assertIn("<label", UI)
        self.assertIn("<form", UI)
        self.assertIn('type="button"', UI)
        self.assertNotIn("dangerouslySetInnerHTML", UI)
        self.assertNotRegex(UI, re.compile(r"\bstyle\s*=\s*\{"))


if __name__ == "__main__":
    unittest.main()

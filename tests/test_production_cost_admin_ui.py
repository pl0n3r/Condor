#!/usr/bin/env python3
"""AC-01 de Condor #723: lectura de snapshots de costos en Producción.

Se verifica el contrato completo entre el panel, el endpoint tenant-scoped,
los estados fail-closed y el refresco por sede, sin clientes ni importes reales.
"""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/ProductionManagement.tsx").read_text(
    encoding="utf-8"
)
CONTROLLER = (ROOT / "src/Http/Controller/ProductionCostController.php").read_text(
    encoding="utf-8"
)
COSTS = (ROOT / "src/Application/Production/ProductionCostService.php").read_text(
    encoding="utf-8"
)


class CostoOrdenUiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Una orden completa consulta y muestra total, unitario y desglose."""
        self.assertIn("order.status === 'completed'", PANEL)
        self.assertIn("productionOrdersPath(branchId) + '/' + encodeURIComponent(order.id) + '/cost'", PANEL)
        self.assertIn("getJson<unknown>(", PANEL)
        self.assertIn("verifiedOrderCost(payload, order.id)", PANEL)
        self.assertIn("Costo total: {copAmount(cost.data.total_cost)}", PANEL)
        self.assertIn("Costo por unidad: {copAmount(cost.data.unit_cost)}", PANEL)
        self.assertIn("Desglose por material", PANEL)
        self.assertIn("cost.data.consumptions.map((line)", PANEL)
        self.assertIn("line.quantity", PANEL)
        self.assertIn("copAmount(line.unit_cost)", PANEL)
        self.assertIn("copAmount(line.line_cost)", PANEL)
        self.assertIn("materials.get(line.material_id)?.name", PANEL)

        self.assertIn("methods: ['GET']", CONTROLLER)
        self.assertIn("'inventory.view'", CONTROLLER)
        self.assertIn("'id' => $orderId,", CONTROLLER)
        self.assertIn("'production_completion_snapshot'", CONTROLLER)
        self.assertIn("'snapshot_persisted' => true", CONTROLLER)
        self.assertIn("'consumptions' => $lines", COSTS)
        self.assertIn("'total_cost' => self::decimal($grandTotal)", COSTS)
        self.assertIn("'unit_cost' => self::decimal($unitTotal)", COSTS)

    def test_unknown_old_orders_never_invent_a_zero_or_reprice(self) -> None:
        self.assertIn("response.state === 'UNKNOWN'", PANEL)
        self.assertIn("response.cost === null", PANEL)
        self.assertIn("response.snapshot_persisted === false", PANEL)
        self.assertIn("status: 'unknown'", PANEL)
        self.assertIn("Costo no disponible: sin comprobante verificable.", PANEL)
        self.assertIn("'state' => 'UNKNOWN'", CONTROLLER)
        self.assertIn("'cost' => null", CONTROLLER)
        self.assertIn("$order->costSnapshot()", CONTROLLER)
        self.assertNotIn("parseFloat(", PANEL)
        self.assertNotIn("parseInt(", PANEL)
        self.assertNotIn("Number(cost.", PANEL)
        self.assertNotIn("Number(line.", PANEL)
        self.assertNotIn("Math.round(", PANEL)

    def test_backend_cost_is_validated_before_display_and_bad_data_fails_closed(self) -> None:
        for contract in (
            "response.order_id !== orderId",
            "response.snapshot_persisted !== true",
            "response.provenance !== 'production_completion_snapshot'",
            "response.state !== 'ESTIMATED_COST'",
            "cost.order_id !== orderId",
            "cost.state !== 'ESTIMATED_COST'",
            "moneyPattern.test(cost.total_cost)",
            "moneyPattern.test(cost.unit_cost)",
            "Array.isArray(cost.consumptions)",
            "quantityPattern.test(row.quantity)",
            "moneyPattern.test(row.line_cost)",
            "moneyPattern.test(row.unit_cost)",
        ):
            with self.subTest(contract=contract):
                self.assertIn(contract, PANEL)
        self.assertIn("return { status: 'error' }", PANEL)
        self.assertIn("No fue posible verificar el costo de la orden.", PANEL)
        self.assertIn('role="alert"', PANEL)
        self.assertIn("status: 'loading'", PANEL)
        self.assertIn("Consultando costo de la orden…", PANEL)
        self.assertIn("No se registraron consumos de material.", PANEL)

    def test_only_completed_order_read_isolated_from_branch_switches(self) -> None:
        self.assertIn("orders.orders.filter((order) => order.status === 'completed')", PANEL)
        self.assertIn("const request = ++costRequestId.current", PANEL)
        self.assertIn("if (request !== costRequestId.current) return", PANEL)
        self.assertIn("return () => { costRequestId.current += 1; }", PANEL)
        self.assertIn("setOrderCosts((current) => ({ ...current, [order.id]: next }))", PANEL)
        self.assertIn("catch((): OrderCostState => ({ status: 'error' }))", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("if (!canView)", PANEL)
        self.assertNotIn("tenant_id:", PANEL)
        self.assertNotIn("Math.abs(", PANEL)


if __name__ == "__main__":
    unittest.main()

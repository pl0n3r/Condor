#!/usr/bin/env python3
"""#1552: witnesses for operational modules, synthetic and never live."""
from __future__ import annotations

import csv
import io
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
import test_cobertura_condor_26 as journeys

ROWS = (
    "OPS-M01-PENDIENTE", "OPS-M01-PRUEBA", "OPS-M02-PENDIENTE",
    "OPS-M03-PENDIENTE", "OPS-M04-PENDIENTE", "OPS-M05-PENDIENTE",
    "OPS-M06-PENDIENTE", "OPS-M07-PENDIENTE", "OPS-M08-PENDIENTE",
    "OPS-REC-A", "OPS-REC-B", "OPS-REC-B-P4", "OPS-REC-C",
    "OPS-REC-C-P4", "OPS-REC-D", "OPS-REC-D-P4",
)
ANCHORS = {
    "OPS-M01-PENDIENTE": "confirmar cada política comercial/mayorista",
    "OPS-M01-PRUEBA": "crear un catálogo pequeño y revisar variantes",
    "OPS-M02-PENDIENTE": "pasarelas de pago, envíos y dominio propio",
    "OPS-M03-PENDIENTE": "facturación y conciliación operativa",
    "OPS-M04-PENDIENTE": "transferencias automáticas intersede",
    "OPS-M05-PENDIENTE": "CRM avanzado, automatizaciones multicanal",
    "OPS-M06-PENDIENTE": "conciliación contable, facturación",
    "OPS-M07-PENDIENTE": "valoración contable definitiva",
    "OPS-M08-PENDIENTE": "validación de los datos y descargas reales",
    "OPS-REC-A": "### Recorrido A — Tienda",
    "OPS-REC-B": "### Recorrido B — Taller",
    "OPS-REC-B-P4": "Comprobar el costo **estimado** total/unitario",
    "OPS-REC-C": "### Recorrido C — Distribuidora con sedes",
    "OPS-REC-C-P4": "transferencias **automáticas**",
    "OPS-REC-D": "### Recorrido D — Grupo de empresas",
    "OPS-REC-D-P4": "Consolidación interempresas, facturación conjunta",
}


class VerificacionOperationalModulesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.guide = (ROOT / "docs/modulos-operativos.md").read_text(encoding="utf-8")
        cls.report = (ROOT / "docs/verificacion/verify-operational-modules.md").read_text(encoding="utf-8")

    def test_mapa_exacto_sin_promocion_de_matriz(self):
        """AC-01: 16 assigned rows with explicit verifiable limits."""
        self.assertEqual(16, len(ROWS))
        self.assertEqual(16, len(set(ROWS)))
        rows = [line for line in self.report.splitlines() if line.startswith("| OPS-")]
        self.assertEqual(16, len(rows))
        for row, entry in zip(ROWS, rows):
            with self.subTest(row=row):
                self.assertTrue(entry.startswith("| " + row + " |"), entry)
                self.assertIn("NO_VERIFICABLE", entry)
                self.assertIn("test_", entry)
                self.assertIn(ANCHORS[row], self.guide)
        self.assertIn("sin modificar docs/matriz-cobertura.md", self.report)
        self.assertIn("CI exact-HEAD", self.report)

    def test_m01_precios_no_cobran_y_rechazan_moneda(self):
        """M01-PENDIENTE/PRUEBA: real preview, not a commercial policy."""
        result = journeys.quote()
        self.assertEqual(("SYNTHETIC_PREVIEW", "COP", 2461),
                         (result["status"], result["currency"], result["total_cop"]))
        self.assertFalse(result["can_order"])
        self.assertFalse(result["can_pay"])
        self.assertFalse(result["persisted"])
        rejected = journeys.quote(currency="USD")
        self.assertIn("error", rejected)
        self.assertNotIn("total_cop", rejected)

    def test_m02_m03_rec_a_checkout_server_authority(self):
        """M02/M03/REC-A: real checkout rejects client total/foreign item."""
        checkout = journeys.checkout_response()
        self.assertEqual(201, checkout["valid_status"])
        self.assertEqual(5600, checkout["tenant_a_server_total"])
        self.assertEqual(422, checkout["client_total"])
        self.assertEqual(404, checkout["foreign_variant"])
        self.assertFalse(journeys.quote()["can_pay"])

    def test_m04_rec_c_csv_branch_tenant_isolation(self):
        """M04/REC-C: real StockCsvAdjuster rejects branch mismatch."""
        alpha = journeys.stock("fixture:tenant/alpha")
        beta = journeys.stock("fixture:tenant/beta")
        self.assertTrue(alpha["ok"] and beta["ok"])
        self.assertNotEqual(alpha["keys"], beta["keys"])
        rejected = journeys.stock(csv_body="sku,branch_id,quantity\nSKU-TEST,bad-site,5\n")
        self.assertTrue(rejected["ok"])
        self.assertEqual([], rejected["parsed"]["rows"])
        self.assertEqual("CSV_ROW_INVALID", rejected["parsed"]["errors"][0]["error"])

    def test_m05_customer_own_tenant_only(self):
        """M05: real domain entity rejects cross-tenant categories."""
        data = journeys.operational.php_run(
            journeys.operational.CUSTOMERS_PHP,
            journeys.operational.CATEGORY_FILES,
        )
        self.assertEqual("minoristas", data["category"])
        self.assertEqual("fixture-tenant-a", data["tenant"])
        self.assertEqual([True] * 5, data["denied"])

    def test_m06_rec_b_receipt_synthetic_only(self):
        """M06/REC-B: real purchase order receipt, no accounting proof."""
        data = journeys.purchasing_order()
        self.assertEqual(("draft", "received", True),
                         (data["before"], data["after"], data["received"]))
        self.assertEqual(1, data["line_count"])
        self.assertEqual([True] * 8, data["denials"])

    def test_m07_rec_b_p4_production_estimated(self):
        """M07/REC-B-P4: real production order; no definitive cost."""
        data = journeys.operational.php_run(
            journeys.operational.PRODUCTION_PHP, [journeys.operational.PRODUCTION]
        )
        self.assertEqual(("draft", "completed", 3),
                         (data["before"], data["after"], data["completed"]))
        self.assertEqual([True] * 7, data["denied"])
        self.assertIn("ESTIMATED_COST", self.guide)
        self.assertTrue((ROOT / "frontend/admin/ProductionManagement.tsx").is_file())

    def test_m08_rec_c_rec_d_report_filters(self):
        """M08/REC-C/REC-D: real CSV service excludes other tenants/dates."""
        result = journeys.tenant_report([
            journeys.operational.report_record("tenant-a", "local", "2026-10-20"),
            journeys.operational.report_record("tenant-b", "foreign", "2026-10-20"),
            journeys.operational.report_record("tenant-a", "late", "2026-10-22"),
        ])
        self.assertTrue(result["ok"])
        lines = list(csv.reader(io.StringIO(result["data"]["body"])))
        self.assertEqual(["local"], [row[0] for row in lines[1:]])
        self.assertNotIn("foreign", result["data"]["body"])
        self.assertNotIn("late", result["data"]["body"])
        self.assertTrue((ROOT / "frontend/admin/ReportsDownloads.tsx").is_file())

    def test_rec_c_p4_rec_d_p4_manual_no_automatic_cross_company(self):
        """REC-C-P4/REC-D-P4: UI/manual routes do not grant new scope."""
        self.assertIn("transferencias **manuales**", self.guide)
        self.assertIn("transferencias **automáticas**", self.guide)
        self.assertTrue((ROOT / "frontend/admin/StockTransferPanel.tsx").is_file())
        self.assertTrue((ROOT / "frontend/admin/StockAlertsPanel.tsx").is_file())
        rejected = journeys.tenant_report([
            {"tenant_id": "tenant-a", "occurred_at": "2026-10-20"}
        ])
        self.assertFalse(rejected["ok"])
        self.assertEqual("INVALID_REPORT_COLUMN", rejected["reason"])
        self.assertIn("autorización explícita", self.guide)

    def test_solo_construccion_fixtures(self):
        """AC-02: no synthetic result can be used as live readiness."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        self.assertTrue(all(line["item_ref"].startswith("fixture:")
                            for line in journeys.operational.quotation()["lines"]))
        self.assertIn("sin cobros reales", self.report)
        self.assertIn("NO_VERIFICABLE", self.report)


if __name__ == "__main__":
    unittest.main()

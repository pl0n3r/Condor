"""Contrato offline de guía, evidencias de código y portada (#722, #1510).

La aceptación se contrasta contra rutas concretas de código que existen en el
checkout; no declara producción verde ni habilita servicios.
"""
from __future__ import annotations

import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUIDE = ROOT / "docs" / "modulos-operativos.md"
SITE = ROOT / "index.html"
ADMIN_APP = ROOT / "frontend" / "admin" / "AdminApp.tsx"
MODULES = (
    "Catálogo y precios",
    "Tienda y checkout",
    "Pedidos y ventas",
    "Inventario y sedes",
    "Clientes y categorías",
    "Compras y proveedores",
    "Producción y materiales",
    "Reportes CSV",
)
SCENARIOS = ("Tienda", "Taller", "Distribuidora con sedes", "Grupo de empresas")
EVIDENCE = (
    "src/Http/Controller/CatalogController.php",
    "src/Http/Controller/StorefrontCheckoutController.php",
    "src/Http/Controller/OrderController.php",
    "src/Http/Controller/InventoryController.php",
    "src/Http/Controller/CustomerController.php",
    "src/Http/Controller/PurchasingController.php",
    "src/Http/Controller/ProductionOperationsController.php",
    "src/Application/Reporting/TenantCsvReportService.php",
)
# Una capacidad movida a «Presente en main» exige API y UI concretas,
# no solo la existencia de un servicio o un texto de marketing.
DELIVERED = {
    4: (
        ("alertas de stock bajo", "src/Http/Controller/StockAlertController.php",
         "frontend/admin/StockAlertsPanel.tsx"),
        ("transferencias manuales", "src/Http/Controller/StockTransferController.php",
         "frontend/admin/StockTransferPanel.tsx"),
        ("escaneo de códigos", "src/Http/Controller/ScanController.php",
         "frontend/admin/InventoryManagement.tsx"),
        ("ajuste CSV", "src/Http/Controller/InventoryCsvAdjustController.php",
         "frontend/admin/StockCsvAdjust.tsx"),
    ),
    6: (
        ("costo promedio", "src/Http/Controller/PurchasingController.php",
         "frontend/admin/PurchasingManagement.tsx"),
    ),
    7: (
        ("costo por orden", "src/Http/Controller/ProductionCostController.php",
         "frontend/admin/ProductionManagement.tsx"),
    ),
    8: (
        ("descarga de reportes CSV", "src/Http/Controller/ReportsController.php",
         "frontend/admin/ReportsDownloads.tsx"),
    ),
}
ADMIN_WIDGETS = (
    "InventoryManagement", "StockAlertsPanel", "StockTransferPanel",
    "PurchasingManagement", "ProductionManagement", "ReportsDownloads",
)
STALE_PENDING = (
    "alertas automáticas de stock bajo y escaneo",
    "la pantalla integral de compras y el costo histórico ponderado",
    "costo total/unitario por orden y su desglose en la UI dependen",
    "la pantalla de descarga con filtros de fecha dentro del administrador está planificada",
)

UNSUPPORTED_PROMISES = (
    "listo para producción",
    "ya disponible en producción",
    "listo para cobrar",
    "se garantizan las integraciones",
    "consolidación entre empresas disponible",
)


def validate_document(document: str) -> list[str]:
    """Devuelve defectos comprobables; toda sección exige límite y evidencia."""
    errors: list[str] = []
    if not document.startswith("# Guía de módulos operativos por tipo de negocio"):
        errors.append("title")
    if re.search(
        r"\*\*Corte de código:\*\* `main@[0-9a-f]{40}` "
        r"\(4 de noviembre de 2026\)", document,
    ) is None:
        errors.append("stale_cutoff")
    sections = re.findall(
        r"(?ms)^### ([1-8])\. ([^\n]+)\n(.*?)(?=^### |^## |\Z)",
        document,
    )
    if len(sections) != len(MODULES):
        errors.append("module_count")
    for n, expected in enumerate(MODULES, 1):
        matching = [body for index, title, body in sections
                    if index == str(n) and title == expected]
        if len(matching) != 1:
            errors.append(f"module_{n}_missing")
            continue
        section = matching[0]
        if "**Presente en main:**" not in section:
            errors.append(f"module_{n}_no_evidence")
        if "**En construcción:**" not in section:
            errors.append(f"module_{n}_unqualified")
        if "**Empezar por:**" not in section:
            errors.append(f"module_{n}_no_next_step")
        if EVIDENCE[n - 1] not in section:
            errors.append(f"module_{n}_source")
        if "**Presente en main:**" in section and "**En construcción:**" in section:
            present = section.split("**Presente en main:**", 1)[1].split(
                "**En construcción:**", 1
            )[0]
            for label, controller, panel in DELIVERED.get(n, ()):
                if not all(token in present for token in (label, controller, panel)):
                    errors.append(f"module_{n}_missing_{label}")
    for letter, scenario in zip("ABCD", SCENARIOS):
        if len(re.findall(
            rf"(?m)^### Recorrido {letter} — {re.escape(scenario)}$",
            document,
        )) != 1:
            errors.append(f"scenario_{letter}")
    if not all(word in document.lower() for word in
               ("pre-live", "aislamiento", "datos reales", "permisos")):
        errors.append("disclaimer")
    if any(phrase in document.lower() for phrase in STALE_PENDING):
        errors.append("obsolete_pending")
    if any(phrase in document.lower() for phrase in UNSUPPORTED_PROMISES):
        errors.append("unsupported_promise")
    return errors


class GuiaModulosOperativosTests(unittest.TestCase):
    def test_acceptance(self):
        self.assertTrue(GUIDE.is_file(), "Falta el documento reclamado")
        data = GUIDE.read_text(encoding="utf-8")
        self.assertEqual(validate_document(data), [])
        for path in EVIDENCE:
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file(),
                                f"La fuente citada no está en el checkout: {path}")

    def test_all_delivered_features_have_real_controller_and_wired_screen(self):
        admin = ADMIN_APP.read_text(encoding="utf-8")
        for widget in ADMIN_WIDGETS:
            with self.subTest(widget=widget):
                self.assertIn(f"<{widget}", admin)
        for rows in DELIVERED.values():
            for label, controller, panel in rows:
                with self.subTest(feature=label):
                    self.assertTrue((ROOT / controller).is_file(), controller)
                    self.assertTrue((ROOT / panel).is_file(), panel)
        inventory = (ROOT / "frontend/admin/InventoryManagement.tsx").read_text(
            encoding="utf-8"
        )
        self.assertIn("<StockCsvAdjust", inventory)

    def test_portada_reconciles_present_code_with_pre_live_limits(self):
        guide = GUIDE.read_text(encoding="utf-8").lower()
        site = SITE.read_text(encoding="utf-8").lower()
        for phrase in ("alertas de stock bajo", "transferencias manuales",
                       "ajustes csv", "costo promedio", "descargas de reportes csv"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, site)
                if phrase != "ajustes csv":
                    self.assertIn(phrase, guide)
        for phrase in ("sin afirmar despliegue", "sitio sigue en preparación",
                       "disponibilidad comercial", "transferencias automáticas"):
            with self.subTest(limit=phrase):
                if phrase == "sitio sigue en preparación":
                    self.assertIn("sitio sigue en preparación", site)
                elif phrase == "sin afirmar despliegue":
                    self.assertIn("sin afirmar despliegue", site)
                else:
                    self.assertIn(phrase, site)
        self.assertIn("transferencias automáticas", guide)

    def test_missing_module_or_disclaimer_is_rejected(self):
        good = GUIDE.read_text(encoding="utf-8")
        self.assertEqual(validate_document(good), [])
        without_module = good.replace("### 8. Reportes CSV", "### 8. Otro módulo")
        self.assertIn("module_8_missing", validate_document(without_module))
        without_pending = good.replace(
            "**En construcción:**", "**Sin límites:**", 1)
        self.assertNotEqual(validate_document(without_pending), [])
        self.assertIn(
            "unsupported_promise",
            validate_document(good + "\nYa disponible en producción.\n"),
        )

    def test_rejects_obsolete_status_and_missing_wiring(self):
        good = GUIDE.read_text(encoding="utf-8")
        self.assertIn("obsolete_pending", validate_document(
            good + "\\nla pantalla integral de compras y el costo histórico ponderado\\n"
        ))
        removed = good.replace(
            "src/Http/Controller/StockAlertController.php",
            "src/Http/Controller/MissingStockAlertController.php", 1
        )
        self.assertIn("module_4_missing_alertas de stock bajo",
                      validate_document(removed))
        self.assertIn("stale_cutoff", validate_document(
            good.replace("4 de noviembre de 2026", "20 de octubre de 2026", 1)
        ))

    def test_all_four_business_scenarios_are_required(self):
        good = GUIDE.read_text(encoding="utf-8")
        for name in SCENARIOS:
            with self.subTest(scenario=name):
                self.assertNotEqual(
                    validate_document(good.replace(name, "Escenario inventado")),
                    [],
                )


if __name__ == "__main__":
    unittest.main()

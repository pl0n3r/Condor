"""Contrato offline de la guía de ocho módulos y cuatro recorridos (#722).

La aceptación se contrasta contra rutas concretas de código que existen en el
checkout; no declara producción verde ni habilita servicios.
"""
from __future__ import annotations

import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUIDE = ROOT / "docs" / "modulos-operativos.md"
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
    for letter, scenario in zip("ABCD", SCENARIOS):
        if len(re.findall(
            rf"(?m)^### Recorrido {letter} — {re.escape(scenario)}$",
            document,
        )) != 1:
            errors.append(f"scenario_{letter}")
    if not all(word in document.lower() for word in
               ("pre-live", "aislamiento", "datos reales", "permisos")):
        errors.append("disclaimer")
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

    def test_missing_module_or_disclaimer_is_rejected(self):
        good = GUIDE.read_text(encoding="utf-8")
        self.assertEqual(validate_document(good), [])
        without_module = good.replace("### 8. Reportes CSV", "### 8. Otro módulo")
        self.assertIn("module_8_missing", validate_document(without_module))
        without_pending = good.replace(
            "**En construcción:** la pantalla", "**Sin límites:** la pantalla")
        self.assertNotEqual(validate_document(without_pending), [])
        self.assertIn(
            "unsupported_promise",
            validate_document(good + "\nYa disponible en producción.\n"),
        )

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

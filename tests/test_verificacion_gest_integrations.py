#!/usr/bin/env python3
"""#1542: seis fronteras GEST-INTEG, testigos locales reales y NO_GO integral.

La fuente de verdad normativa no está etiquetada con IDs: la identidad de cada
fila se empareja aquí con su nombre literal, y los testigos ejecutan PHP real
con fixtures sintéticos. No se contactan servicios externos.
"""
from __future__ import annotations

import importlib.util
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "docs/gestion-empresarial-integral.md"
REPORT = ROOT / "docs/verificacion/gest-integrations.md"

# (id, frontera literal en el contrato, source real, [(test, clase, método)])
ROWS = (
    (
        "GEST-INTEG-01",
        "Comercio, pedidos, inventario, compras y producción",
        ("src/Application/Inventory/ChannelInventorySource.php",),
        (
            ("tests/test_channel_inventory_source.py", "FuenteInventarioCanalTests", "test_acceptance"),
            ("tests/test_order_fulfillment_api.py", "CumplimientoEntregaApiTests", "test_acceptance"),
        ),
    ),
    (
        "GEST-INTEG-02",
        "Entitlements vs permisos",
        ("src/Application/Catalog/CatalogCapabilityGuard.php", "src/Application/Commercial/SaasControlCenterReader.php"),
        (
            ("tests/test_capability_gap_p0_wave1.py", "CapabilityGapP0Wave1Tests", "test_guard_pure_enforces_capability_and_tenant"),
            ("tests/test_saas_control_center_reader_contract.py", "SaasControlCenterReaderContractTests", "test_unauthorized_or_cross_tenant_calls_fail_closed"),
        ),
    ),
    (
        "GEST-INTEG-03",
        "Documento/firma",
        ("src/Application/Documents/DocumentApiService.php",),
        (
            ("tests/test_documents_http_api.py", "ModuloTests", "test_sin_datos_reales"),
            ("tests/test_documents_http_api.py", "ModuloTests", "test_estados_explicitos"),
        ),
    ),
    (
        "GEST-INTEG-04",
        "Nómina, PILA, DIAN y facturación",
        ("src/Application/Payroll/PayrollApiService.php", "src/Application/Commercial/BillingAdapter.php"),
        (
            ("tests/test_payroll_http_api.py", "ModuloTests", "test_sin_datos_reales"),
            ("tests/test_billing_adapter_contract.py", "BillingAdapterContractTests", "test_sensitive_ambiguous_or_provider_specific_payload_fails_closed"),
        ),
    ),
    (
        "GEST-INTEG-05",
        "Correo/notificaciones",
        ("src/Domain/Notification/NotificationCatalog.php",),
        (
            ("tests/test_cobertura_condor_12.py", "CoberturaCondor12Tests", "test_contrato_principal"),
            ("tests/test_cobertura_condor_12.py", "CoberturaCondor12Tests", "test_falla_cerrado"),
        ),
    ),
    (
        "GEST-INTEG-06",
        "BI/exportación",
        ("src/Application/Commercial/TenantOffboardingExport.php",),
        (
            ("tests/test_saas_offboarding_export.py", "SaasTests", "test_aislamiento_por_empresa"),
            ("tests/test_saas_offboarding_export.py", "SaasTests", "test_auditoria_y_permisos"),
        ),
    ),
)


def run_witness(filename: str, class_name: str, method: str) -> unittest.TestResult:
    """La eliminación/rotura de cualquier testigo hace fallar el agregador."""
    if not re.fullmatch(r"tests/test_[a-z0-9_]+\.py", filename):
        raise AssertionError("UNKNOWN: ruta de testigo no autorizada")
    path = ROOT / filename
    if not path.is_file() or path.is_symlink():
        raise AssertionError(f"UNKNOWN: testigo ausente: {filename}")
    name = "_condor1542_" + path.stem
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: no se puede importar el testigo")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    case_class = getattr(module, class_name)
    if not issubclass(case_class, unittest.TestCase):
        raise AssertionError("UNKNOWN: testigo no es unittest")
    case = case_class(method)
    result = unittest.TestResult()
    case.run(result)
    return result


class GestIntegrationsVerification(unittest.TestCase):
    def test_01_identidad_normativa_y_codigo_real(self) -> None:
        spec = SPEC.read_text(encoding="utf-8")
        ids = [row[0] for row in ROWS]
        self.assertEqual([f"GEST-INTEG-{i:02d}" for i in range(1, 7)], ids)
        self.assertEqual(6, len(set(ids)))
        for identifier, label, sources, witnesses in ROWS:
            with self.subTest(row=identifier):
                self.assertIn(f"| {label} |", spec, "frontera normativa ausente")
                self.assertGreaterEqual(len(witnesses), 1)
                for source in sources:
                    path = ROOT / source
                    self.assertTrue(path.is_file() and not path.is_symlink(), source)
                    self.assertIn("<?php", path.read_text(encoding="utf-8")[:100])

    def test_02_brecha_individual_y_sin_promocion_ficticia(self) -> None:
        report = REPORT.read_text(encoding="utf-8")
        self.assertIn("NO_VERIFICABLE integral", report)
        self.assertIn("#742/B", report)
        for identifier, _, sources, witnesses in ROWS:
            with self.subTest(row=identifier):
                lines = re.findall(
                    r"(?m)^\|\s*" + re.escape(identifier) + r"\s*\|[^\n]*$", report
                )
                self.assertEqual(1, len(lines), f"fila {identifier} duplicada/ausente")
                line = lines[0]
                self.assertIn("PARCIAL (offline)", line)
                self.assertIn("NO_VERIFICABLE integral", line)
                for source in sources:
                    self.assertIn(source, line)
                for filename, _, method in witnesses:
                    self.assertIn(filename, line)
                    self.assertIn(method, line)

    def test_03_testigos_php_ejecutables_y_negativos(self) -> None:
        # Cada caso invoca un test preexistente con assertions, no una búsqueda
        # superficial de nombres de métodos o mocks declarados exitosos.
        seen = set()
        for identifier, _, _, witnesses in ROWS:
            for filename, class_name, method in witnesses:
                key = (filename, class_name, method)
                self.assertNotIn(key, seen, f"testigo duplicado: {key}")
                seen.add(key)
                with self.subTest(row=identifier, witness=method):
                    result = run_witness(*key)
                    self.assertEqual(1, result.testsRun, key)
                    self.assertTrue(
                        result.wasSuccessful(),
                        f"UNKNOWN: {identifier} {key}: "
                        f"errors={result.errors!r}, failures={result.failures!r}, "
                        f"skips={result.skipped!r}",
                    )
                    self.assertFalse(result.skipped, "testigo omitido")

    def test_04_diagnostico_fail_closed(self) -> None:
        # No existe integración/proveedor verificado en esta hoja.
        # Testigos verdes no autorizan firmas, RR. HH., ni exportaciones reales.
        report = REPORT.read_text(encoding="utf-8")
        for phrase in ("sin adaptador de firma", "no hay transmisión real PILA/DIAN",
                       "no demuestra cola real", "sin prueba transversal",
                       "no transforma una prueba local", "UNKNOWN"):
            self.assertIn(phrase, report)
        self.assertNotIn("live_authorized=true", report)
        self.assertNotIn("| CUBIERTO |", report)


if __name__ == "__main__":
    unittest.main()

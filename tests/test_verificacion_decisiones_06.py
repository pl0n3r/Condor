#!/usr/bin/env python3
"""#1549: testigos ejecutables de 18 decisiones; NO_VERIFICABLE integral.

Reutiliza pruebas PHP reales ya fusionadas con datos sintéticos: no hace
HTTP externo, no contacta hosting, no toca la base de datos de producción.
La hoja hija #1599 conserva las puertas de integración no verificadas.
"""
from __future__ import annotations

import re
import runpy
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "ESPECIFICACIONES.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
REPORT = ROOT / "docs/verificacion/decisiones-06.md"
BT = chr(96)
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"

# (id, título canónico, apartado normativo, implementación, prueba previa,
#  PR autenticable desde GitHub, clase de evidencia, brecha integral)
ROWS = [
  [
    "D-049-S05",
    "Dominio primario, SEO e integraciones",
    "#### Dominio primario, SEO e integraciones",
    "src/Application/Storefront/StorefrontPresentation.php",
    "tests/test_cobertura_condor_11.py",
    1379,
    "A parcial",
    "Falta DNS/TLS y canonical con host verificado y observación externa."
  ],
  [
    "D-049-S06",
    "Administración",
    "#### Administración",
    "src/Http/Controller/StorefrontAdminController.php",
    "tests/test_cobertura_condor_11.py",
    1379,
    "A parcial",
    "Falta autorización UI/HTTP integral, dominios y revocación del tenant."
  ],
  [
    "D-049-S07",
    "Storefront mínimo inicial",
    "#### Storefront mínimo inicial",
    "src/Http/Controller/TenantPublicController.php",
    "tests/test_cobertura_condor_11.py",
    1379,
    "A parcial",
    "Falta recorrido navegador completo y aislamiento de contenido en despliegue."
  ],
  [
    "D-049-S08",
    "Hosting y portabilidad",
    "#### Hosting y portabilidad",
    "src/Infrastructure/Tenancy/TenantResolver.php",
    "tests/test_brechas_condor_19.py",
    1274,
    "B externa",
    "Falta prueba real de dominios múltiples, TLS, routing y portabilidad."
  ],
  [
    "D-050",
    "Staff de plataforma, invitaciones y notificaciones configurables",
    "### D-050 — Staff de plataforma, invitaciones y notificaciones configurables",
    "src/Http/Controller/PlatformStaffController.php",
    "tests/test_brechas_condor_19.py",
    1274,
    "B integral",
    "Falta aceptación end-to-end de las seis capacidades y permisos."
  ],
  [
    "D-050-S01",
    "Staff de plataforma",
    "#### Staff de plataforma",
    "src/Http/Controller/PlatformStaffController.php",
    "tests/test_cobertura_condor_11.py",
    1379,
    "A parcial",
    "Falta probar todo el ciclo de grants, revocación y auditoría efectiva."
  ],
  [
    "D-050-S02",
    "Invitaciones y activación",
    "#### Invitaciones y activación",
    "src/Application/Identity/AcceptAccountInvitation.php",
    "tests/test_brechas_condor_19.py",
    1274,
    "B integral",
    "Falta integración HTTP completa, expiración y activación autorizada."
  ],
  [
    "D-050-S03",
    "Correo transaccional",
    "#### Correo transaccional",
    "src/Infrastructure/Notification/NullTransactionalEmailGateway.php",
    "tests/test_cobertura_condor_12.py",
    1381,
    "A parcial",
    "El Null adapter no acredita entrega de correo o proveedor configurado."
  ],
  [
    "D-050-S04",
    "Notificaciones",
    "#### Notificaciones",
    "src/Domain/Notification/NotificationCatalog.php",
    "tests/test_cobertura_condor_12.py",
    1381,
    "A parcial",
    "Falta ciclo persistido, destinatarios autorizados y entrega efectiva."
  ],
  [
    "D-050-S05",
    "Adaptabilidad",
    "#### Adaptabilidad",
    "src/Application/Notification/NotificationPreferenceService.php",
    "tests/test_brechas_condor_20.py",
    1273,
    "B integral",
    "Falta integración de preferencias y capacidad por tenant con UI."
  ],
  [
    "D-050-S06",
    "UX progresiva",
    "#### UX progresiva",
    "frontend/admin/PlatformOwnerApp.tsx",
    "tests/test_brechas_condor_20.py",
    1273,
    "B integral",
    "Faltan recorridos reales por rol y estados de carga/errores en navegador."
  ],
  [
    "D-051",
    "Integraciones mediante adaptadores",
    "### D-051 — Integraciones mediante adaptadores",
    "src/Application/Commercial/BillingAdapter.php",
    "tests/test_cobertura_condor_12.py",
    1381,
    "A parcial",
    "Falta probar binding real por DI, sin red y sin PII, por proveedor."
  ],
  [
    "D-052",
    "Titularidad jurídica por entidad legal e intercompany explícito",
    "### D-052 — Titularidad jurídica por entidad legal e intercompany explícito",
    "src/Application/Inventory/StockTransferService.php",
    "tests/test_brechas_condor_21.py",
    1276,
    "B integral",
    "Falta prueba persistente y negativa intercompany/cross-tenant en todas las operaciones."
  ],
  [
    "D-053",
    "Producción operable durante etapa de construcción",
    "### D-053 — Producción operable durante etapa de construcción",
    "scripts/post-deploy.sh",
    "tests/test_brechas_condor_21.py",
    1276,
    "B operativa",
    "Falta recibo de observer y estado real exact-SHA del entorno de construcción."
  ],
  [
    "D-054",
    "Auto-migración aditiva con backup durante pre-lanzamiento",
    "### D-054 — Auto-migración aditiva con backup durante pre-lanzamiento",
    "scripts/post-deploy.sh",
    "tests/test_cobertura_condor_12.py",
    1381,
    "A parcial",
    "Sandbox simulado no sustituye evidencia de backup/restauración y migración real."
  ],
  [
    "SPEC-5-5",
    "Regla de esta fase",
    "### 5.5 Regla de esta fase",
    "ESPECIFICACIONES.md",
    "tests/test_cobertura_condor_30.py",
    1403,
    "C normativa",
    "Decisión de producto editorial; requiere constancia del responsable, no pseudo-test runtime."
  ],
  [
    "SPEC-BASE-3",
    "Infraestructura objetivo inicial",
    "## 4. Infraestructura objetivo inicial",
    "composer.json",
    "tests/test_cobertura_condor_30.py",
    1403,
    "B externa",
    "Falta hosting, versiones, observabilidad y portabilidad real autenticada."
  ],
  [
    "SPEC-BASE-4",
    "Definición funcional del producto",
    "## 5. Definición funcional del producto",
    "src/Application/Storefront/PublicCatalogSearch.php",
    "tests/test_cobertura_condor_30.py",
    1403,
    "A parcial",
    "Falta aceptación funcional transversal por cada capacidad MVP y rol."
  ]
]

IDS = {row[0] for row in ROWS}


def matrix_rows(text: object) -> dict[str, tuple[str, ...]]:
    """Rechazar filas ausentes, duplicadas, promovidas o fuente sustituida."""
    if not isinstance(text, str):
        raise ValueError("UNKNOWN: matriz ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        if not line.startswith("| " + BT):
            continue
        key = line.split(BT, 2)[1]
        if key not in IDS:
            continue
        columns = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in found or len(columns) != 5
                or columns[0] != BT + key + BT + " · " + BT + "ESPECIFICACIONES.md" + BT
                or columns[2] != BT + "NO_VERIFICABLE" + BT
                or columns[4] != "#1" or not columns[3]):
            raise ValueError("UNKNOWN: fila ambigua/promovida " + key)
        found[key] = columns
    if set(found) != IDS:
        raise ValueError("UNKNOWN: lote incompleto")
    return found


def section(text: object, heading: str) -> str:
    """Aislar la sección exacta, sin aceptar coincidencias de otras decisiones."""
    if not isinstance(text, str):
        raise ValueError("UNKNOWN: especificación ilegible")
    lines = text.splitlines()
    starts = [i for i, line in enumerate(lines) if line == heading]
    if len(starts) != 1:
        raise ValueError("UNKNOWN: sección ausente/duplicada " + heading)
    first = starts[0]
    depth = len(heading) - len(heading.lstrip("#"))
    for last in range(first + 1, len(lines)):
        match = re.match(r"^(#{1,6})\s+", lines[last])
        if match and len(match.group(1)) <= depth:
            return "\n".join(lines[first:last])
    return "\n".join(lines[first:])


def integral_state(identifier: object, _receipt: object = None) -> str:
    """Tests locales/PR no sustituyen validación end-to-end ni autorización."""
    return UNKNOWN


def run_existing_behavior(filename: str, classname: str, method: str) -> None:
    """Ejecutar el test existente y sus aserciones PHP, no leer solo su nombre."""
    allowlist = {
        ("test_cobertura_condor_11.py", "CoberturaCondor11Tests",
         "test_contrato_principal"),
        ("test_cobertura_condor_11.py", "CoberturaCondor11Tests",
         "test_falla_cerrado"),
        ("test_cobertura_condor_12.py", "CoberturaCondor12Tests",
         "test_contrato_principal"),
        ("test_cobertura_condor_12.py", "CoberturaCondor12Tests",
         "test_falla_cerrado"),
        ("test_cobertura_condor_30.py", "CoberturaCondor30Tests",
         "test_contrato_principal"),
        ("test_cobertura_condor_30.py", "CoberturaCondor30Tests",
         "test_falla_cerrado"),
    }
    if (filename, classname, method) not in allowlist:
        raise AssertionError("UNKNOWN: suite no autorizada")
    namespace = runpy.run_path(str(ROOT / "tests" / filename))
    klass = namespace.get(classname)
    if not isinstance(klass, type) or not issubclass(klass, unittest.TestCase):
        raise AssertionError("UNKNOWN: suite inválida")
    result = unittest.TestResult()
    klass(method).run(result)
    if result.testsRun != 1 or result.errors or result.failures or result.skipped:
        raise AssertionError("UNKNOWN: test PHP real no pasó: " + filename)


class VerificacionDecisiones06Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.report = REPORT.read_text(encoding="utf-8")

    def test_inventario_y_fuentes_exactos(self) -> None:
        """AC-01: 18 filas, fuente/test/pr previa y brecha por ID."""
        self.assertEqual(18, len(ROWS))
        self.assertEqual(18, len(IDS))
        found = matrix_rows(self.matrix)
        for identifier, title, heading, source, witness, prior, kind, gap in ROWS:
            with self.subTest(id=identifier):
                row = found[identifier]
                self.assertEqual(title, row[1])
                self.assertEqual(BT + "NO_VERIFICABLE" + BT, row[2])
                self.assertIn(identifier, row[3])
                self.assertIn("PR #" + str(prior) + " MERGED", row[3])
                self.assertIn(heading, section(self.spec, heading))
                self.assertTrue((ROOT / source).is_file(), source)
                self.assertTrue((ROOT / witness).is_file(), witness)
                self.assertTrue(gap and kind)
                self.assertEqual(UNKNOWN, integral_state(identifier, found))
                self.assertIn("| " + identifier + " |", self.report)
                self.assertIn(source, self.report)
                self.assertIn(witness, self.report)
                self.assertIn("#1599", self.report)
        self.assertIn("NO_VERIFICABLE", self.report)
        self.assertNotIn("CUBIERTO integral", self.report)

    def test_pruebas_reales_de_storefront_y_staff(self) -> None:
        """AC-02: ejecutar positivo + negativos reales de tenant/CSRF/grants."""
        run_existing_behavior("test_cobertura_condor_11.py",
                              "CoberturaCondor11Tests", "test_contrato_principal")
        run_existing_behavior("test_cobertura_condor_11.py",
                              "CoberturaCondor11Tests", "test_falla_cerrado")

    def test_pruebas_reales_adaptadores_y_migraciones(self) -> None:
        """AC-02: catalogo de eventos, adapter, D-054 y denegaciones reales."""
        run_existing_behavior("test_cobertura_condor_12.py",
                              "CoberturaCondor12Tests", "test_contrato_principal")
        run_existing_behavior("test_cobertura_condor_12.py",
                              "CoberturaCondor12Tests", "test_falla_cerrado")

    def test_pruebas_reales_baseline_y_producto(self) -> None:
        """AC-02: ejecutar clases PHP reales y negativos de interfaz local."""
        run_existing_behavior("test_cobertura_condor_30.py",
                              "CoberturaCondor30Tests", "test_contrato_principal")
        run_existing_behavior("test_cobertura_condor_30.py",
                              "CoberturaCondor30Tests", "test_falla_cerrado")

    def test_falla_cerrado_si_alteran_filas_o_norma(self) -> None:
        """AC-02: mutación de la matriz/norma invalida la evidencia."""
        found = matrix_rows(self.matrix)
        example = next(line for line in self.matrix.splitlines()
                       if line.startswith("| " + BT + "D-054" + BT + " ·"))
        with self.assertRaises(ValueError):
            matrix_rows(self.matrix + "\n" + example)
        with self.assertRaises(ValueError):
            matrix_rows(self.matrix.replace(
                BT + "D-054" + BT, BT + "D-054-QUITADO" + BT, 1))
        with self.assertRaises(ValueError):
            promoted = self.matrix.replace(
                example,
                example.replace(BT + "NO_VERIFICABLE" + BT,
                                BT + "CUBIERTO" + BT, 1), 1)
            matrix_rows(promoted)
        with self.assertRaises(ValueError):
            section(self.spec.replace("### D-054 —", "### DELETED —"),
                    "### D-054 — Auto-migración aditiva con backup durante pre-lanzamiento")
        for key in IDS:
            self.assertEqual(UNKNOWN, integral_state(key, {
                "sha": "a" * 40, "ci": "success", "deployed": True}))
        self.assertEqual(UNKNOWN, integral_state("D-999", found))
        selection = classify(["tests/test_verificacion_decisiones_06.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)
        with self.assertRaises(AssertionError):
            run_existing_behavior("test_inventado.py", "Fake", "testFake")


if __name__ == "__main__":
    unittest.main()

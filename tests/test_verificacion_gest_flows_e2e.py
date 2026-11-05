#!/usr/bin/env python3
"""#1541: 18 flow rows, real PHP subcontracts, explicit non-E2E gaps."""
from __future__ import annotations

import re
import runpy
import unittest
from pathlib import Path

from scripts.ci_change_classifier import classify

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs/gestion-empresarial-integral.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
REPORT = ROOT / "docs/verificacion/gest-flows-e2e.md"
CHILD = "#1620"
SOURCE_REF = "docs/gestion-empresarial-integral.md"
UNKNOWN = "UNKNOWN"
NO_VERIFICABLE = "NO_VERIFICABLE"

# Row, real application source, executable existing synthetic witness, specific
# missing integrated invariant. A witness is partial, NEVER full-flow certification.
ROWS = (
    ("GEST-FLUJO-1", "src/Application/Compliance/NormativeApplicability.php",
     "tests/test_cobertura_condor_13.py", "faltan permisos integrales y revisión profesional del perfil"),
    ("GEST-FLUJO-1-P01", "src/Application/Compliance/NormativeApplicability.php",
     "tests/test_cobertura_condor_13.py", "falta creación persistida de perfil por entidad/sede y RBAC"),
    ("GEST-FLUJO-1-P02", "src/Application/Compliance/NormativeMatrix.php",
     "tests/test_cobertura_condor_14.py", "faltan fuentes oficiales vigentes y revisor autenticado"),
    ("GEST-FLUJO-1-P03", "src/Application/Compliance/ComplianceEvidenceEvaluation.php",
     "tests/test_cobertura_condor_14.py", "faltan obligaciones asignadas y vencimientos persistidos"),
    ("GEST-FLUJO-1-P04", "src/Application/Compliance/ComplianceEvidenceEvaluation.php",
     "tests/test_cobertura_condor_13.py", "falta subida de evidencia y decisión autorizada por actor distinto"),
    ("GEST-FLUJO-1-P05", "src/Application/Compliance/ComplianceAttentionProjection.php",
     "tests/test_cobertura_condor_14.py", "falta comprobar que alertas no bloquean actos legales indispensables"),
    ("GEST-FLUJO-2", "src/Application/Documents/DocumentDossierVersions.php",
     "tests/test_cobertura_condor_14.py", "falta expediente documental HTTP/DB y política integral"),
    ("GEST-FLUJO-2-P01", "src/Application/Documents/DocumentApprovalRetention.php",
     "tests/test_cobertura_condor_14.py", "falta aplicar RBAC/retención de documentos cargados"),
    ("GEST-FLUJO-2-P02", "src/Application/Documents/DocumentDossierVersions.php",
     "tests/test_cobertura_condor_14.py", "falta carga y hash persistentes con autor/proceso"),
    ("GEST-FLUJO-2-P03", "src/Application/Documents/DocumentApprovalRetention.php",
     "tests/test_cobertura_condor_14.py", "falta aprobación humana competente y revisión de historial real"),
    ("GEST-FLUJO-2-P04", "src/Application/Documents/DocumentApprovalRetention.php",
     "tests/test_cobertura_condor_14.py", "faltan legal hold, exportación, eliminación y auditoría real"),
    ("GEST-FLUJO-3", "src/Application/Risk/RiskRegisterControls.php",
     "tests/test_cobertura_condor_15.py", "falta ciclo conectado de riesgo, incidente, calidad y mejora"),
    ("GEST-FLUJO-3-P01", "src/Application/Risk/RiskIncidentTreatment.php",
     "tests/test_cobertura_condor_14.py", "falta registrar incidentes persistentes con permisos y severidad"),
    ("GEST-FLUJO-3-P02", "src/Application/Risk/RiskRegisterControls.php",
     "tests/test_cobertura_condor_15.py", "falta responsable real y evaluación de control con evidencia"),
    ("GEST-FLUJO-3-P03", "src/Application/Quality/QualityNonconformityActions.php",
     "tests/test_cobertura_condor_15.py", "falta verificación de eficacia y cierre humano aprobado"),
    ("GEST-FLUJO-3-P04", "src/Application/Reporting/TenantCsvReportService.php",
     "tests/test_cobertura_condor_15.py", "falta tablero sensible con RBAC y supresión de PII/denuncias"),
    ("GEST-FLUJO-4", "src/Application/Commercial/EntitlementSnapshot.php",
     "tests/test_cobertura_condor_15.py", "bloqueo jurídico B de #742/D-059/D-063 impide ejecución laboral"),
    ("GEST-FLUJO-5", "src/Application/Assets/AssetRegisterLifecycle.php",
     "tests/test_cobertura_condor_15.py", "faltan transacciones y permisos entre compras, activos y operación"),
)
IDS = frozenset(entry[0] for entry in ROWS)
SUITES = {
    "tests/test_cobertura_condor_13.py": "CoberturaCondor13Tests",
    "tests/test_cobertura_condor_14.py": "CoberturaCondor14Tests",
    "tests/test_cobertura_condor_15.py": "CoberturaCondor15Tests",
}


def matrix_rows(text: object) -> dict[str, tuple[str, ...]]:
    """Reject omissions, duplication, promotion to CUBIERTO, and changed source."""
    if not isinstance(text, str):
        raise ValueError(UNKNOWN + ": matriz ilegible")
    entries: dict[str, tuple[str, ...]] = {}
    prefix = re.compile(r"^\| " + r"\x60(GEST-FLUJO-[1-5](?:-P\d{2})?)\x60 · \x60"
                        + re.escape(SOURCE_REF) + r"\x60 \|")
    for line in text.splitlines():
        found = prefix.match(line)
        if not found:
            continue
        key = found.group(1)
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if (key not in IDS or key in entries or len(cells) != 5
                or cells[0] != f"{chr(96)}{key}{chr(96)} · {chr(96)}{SOURCE_REF}{chr(96)}"
                or cells[2] != f"{chr(96)}{NO_VERIFICABLE}{chr(96)}"
                or cells[4] != "#802"
                or len(cells[1]) < 12 or "Brecha actual" not in cells[3]):
            raise ValueError(UNKNOWN + ": fila cambiada " + key)
        entries[key] = cells
    if set(entries) != IDS:
        raise ValueError(UNKNOWN + ": faltan flujos o pasos")
    return entries


def verify_report(text: object) -> None:
    if not isinstance(text, str):
        raise ValueError(UNKNOWN + ": reporte ausente")
    for key, source, witness, gap in ROWS:
        matching = [line for line in text.splitlines()
                    if line.startswith(f"| {key} |")]
        if (len(matching) != 1
                or source not in matching[0] or witness not in matching[0]
                or gap not in matching[0] or NO_VERIFICABLE not in matching[0]
                or CHILD not in matching[0]):
            raise ValueError(UNKNOWN + ": evidencia o hija no verificable " + key)
    if text.count("| GEST-FLUJO-") != len(IDS):
        raise ValueError(UNKNOWN + ": filas del informe duplicadas")


def run_existing(path: str, method: str) -> None:
    """Call genuine unittest cases, not imitated PHP application code."""
    if path not in SUITES or method not in (
            "test_contrato_principal", "test_falla_cerrado", "test_sin_datos_reales"):
        raise AssertionError(UNKNOWN + ": suite no permitida")
    namespace = runpy.run_path(str(ROOT / path))
    klass = namespace.get(SUITES[path])
    if not isinstance(klass, type) or not issubclass(klass, unittest.TestCase):
        raise AssertionError(UNKNOWN + ": clase no auténtica")
    result = unittest.TestResult()
    klass(method).run(result)
    if result.testsRun != 1 or result.failures or result.errors or result.skipped:
        raise AssertionError(UNKNOWN + ": testigo real no pasa " + path
                             + " " + str((result.failures + result.errors)[:1]))


class GestFlowsE2EVerificationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.spec = SOURCE.read_text(encoding="utf-8")
        cls.report = REPORT.read_text(encoding="utf-8")

    def test_18_filas_trazadas_sin_falsa_certificacion(self) -> None:
        self.assertEqual(18, len(ROWS))
        self.assertEqual(18, len(IDS))
        parsed = matrix_rows(self.matrix)
        verify_report(self.report)
        headings = ("### 1. Perfil y cumplimiento", "### 2. Documento controlado",
                    "### 3. Riesgo, calidad y mejora",
                    "### 4. Ciclos laborales futuros (expresamente bloqueados)",
                    "### 5. Abastecimiento, activos y operación")
        self.assertTrue(all(self.spec.count(title) == 1 for title in headings))
        for key, source, witness, gap in ROWS:
            with self.subTest(key=key):
                self.assertIn("NO_VERIFICABLE", parsed[key][2])
                self.assertTrue((ROOT / source).is_file(), source)
                witness_text = (ROOT / witness).read_text(encoding="utf-8")
                self.assertIn("def test_falla_cerrado(", witness_text)
                self.assertTrue(gap.startswith(("falta", "faltan", "bloqueo")))
                self.assertIn(key, self.report)
        self.assertIn("La decisión B de [#742]", self.spec)
        self.assertIn("No se guardan historias clínicas", self.spec)
        self.assertIn("no se usa go-live", self.report.lower())
        self.assertIn("#1620", self.report)

    def test_php_real_reglas_evidencia_y_documentos(self) -> None:
        # Cobertura #13/14 exercise genuine PHP, no stub claiming an E2E browser flow.
        run_existing("tests/test_cobertura_condor_13.py", "test_contrato_principal")
        previous = runpy.run_path(str(ROOT / "tests/test_cobertura_condor_14.py"))
        rule, context = previous["normative"]()
        regulatory = previous["php_call"](
            "rule", {"rule": rule, "context": context, "as_of": "2026-06-01"})
        self.assertEqual("OBLIGATORIO", regulatory["kind"])
        self.assertEqual("APLICA", regulatory["applicability"])
        dossier = previous["php_call"]("dossier", previous["dossiers"]())
        self.assertTrue(dossier["valid"])
        self.assertFalse(dossier["can_approve"])
        review = previous["php_call"]("approval", previous["approval"]())
        self.assertTrue(review["valid"])
        self.assertFalse(review["automatic_approval"])
        self.assertFalse(review["automatic_deletion"])

    def test_php_real_riesgo_calidad_activo_y_seguridad(self) -> None:
        run_existing("tests/test_cobertura_condor_15.py", "test_contrato_principal")
        run_existing("tests/test_cobertura_condor_13.py", "test_falla_cerrado")
        run_existing("tests/test_cobertura_condor_14.py", "test_falla_cerrado")
        run_existing("tests/test_cobertura_condor_15.py", "test_falla_cerrado")
        run_existing("tests/test_cobertura_condor_15.py", "test_sin_datos_reales")

    def test_fail_closed_ante_fila_y_fuente_desconocida(self) -> None:
        original = self.matrix
        line = next(line for line in original.splitlines()
                    if line.startswith("| " + chr(96) + "GEST-FLUJO-3-P04" + chr(96)))
        mutations = (original + "\n" + line,
                     original.replace(line, "", 1),
                     original.replace(line, line.replace(chr(96) + NO_VERIFICABLE
                                     + chr(96), chr(96) + "CUBIERTO" + chr(96)), 1))
        for altered in mutations:
            with self.assertRaises(ValueError):
                matrix_rows(altered)
        with self.assertRaises(ValueError):
            matrix_rows(None)
        with self.assertRaises(ValueError):
            verify_report(self.report.replace(CHILD, "#unknown"))
        with self.assertRaises(ValueError):
            verify_report(self.report.replace("GEST-FLUJO-4", "GEST-FLUJO-X", 1))
        with self.assertRaises(AssertionError):
            run_existing("tests/unknown.py", "test_contrato_principal")
        with self.assertRaises(AssertionError):
            run_existing("tests/test_cobertura_condor_13.py", "test_not_real")

    def test_clasificador_y_limites(self) -> None:
        selection = classify(["tests/test_verificacion_gest_flows_e2e.py"], "pull_request")
        self.assertTrue(selection.pruebas_base)
        self.assertNotIn("CUBIERTO integral", self.report)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", self.report)


if __name__ == "__main__":
    unittest.main()

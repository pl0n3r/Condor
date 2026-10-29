#!/usr/bin/env python3
"""Condor #1331: contratos offline ejecutables, sin certificar producción.

Reutiliza probes PHP que invocan las clases reales con fixtures sintéticos.
Las filas B no reciben un falso veredicto de cobertura integral.
"""
from __future__ import annotations

import ast
import importlib.util
import json
import re
import shutil
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = (
    "D-050-S02", "D-050-S03", "D-050-S04", "D-050-S05",
    "D-050-S06", "D-051", "D-052", "D-053", "D-054",
    "GEST-AC-01", "GEST-AC-02", "GEST-AC-03",
)
OFFLINE = {
    "D-050-S03": "NotificationCatalog: eventos/canales de correo",
    "D-050-S04": "NotificationCatalog: notificaciones obligatorias",
    "D-051": "BillingRequest / BillingAdapter / BillingResult",
    "D-054": "release_evidence.development_transition_safety",
    "GEST-AC-01": "NormativeApplicability.evaluate",
    "GEST-AC-02": "ComplianceApiService.createRecord/changeState",
    "GEST-AC-03": "ComplianceApiService.viewRecord/listRecords",
}
# Los demás requisitos implican autorización, identidad o evidencia real.
UNVERIFIABLE = {
    "D-050-S02": ("B", "Activación integral requiere HTTP, tokens, permisos y persistencia aislada."),
    "D-050-S05": ("B", "Configuración adaptativa integral depende del runtime por tenant."),
    "D-050-S06": ("B", "UX progresiva integral exige navegador y staff autenticado."),
    "D-052": ("B", "Operación intercompany integral requiere entidades y transacciones aisladas."),
    "D-053": ("B", "Operatividad integral exige observer y post-deploy en hosting real."),
}


def load_probe(path: str):
    file = ROOT / path
    if not file.is_file() or file.is_symlink():
        raise AssertionError("UNKNOWN: probe inexistente " + path)
    spec = importlib.util.spec_from_file_location("condor1331_" + file.stem, file)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: importación no disponible " + path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CoberturaCondor12Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: ejercer PHP real; los B nunca se promueven por evidencia local."""
        self.assertEqual(set(IDS), set(OFFLINE) | set(UNVERIFIABLE))
        self.assertFalse(set(OFFLINE) & set(UNVERIFIABLE))
        for key, (category, reason) in UNVERIFIABLE.items():
            with self.subTest(key=key):
                self.assertEqual("B", category)
                self.assertGreater(len(reason), 35)

        notification = load_probe("tests/test_brechas_condor_20.py").smoke()
        self.assertEqual(["email", "in_app"], notification["channels"])
        self.assertTrue(notification["mandatory_invitation"])
        self.assertTrue(notification["mandatory_password_reset"])
        self.assertFalse(notification["optional_order"])
        self.assertTrue(notification["invalid_event"])
        self.assertTrue(notification["invalid_channel"])
        self.assertEqual("COP", notification["billing_request"]["currency"])
        self.assertEqual("prepared", notification["billing_result"]["outcome"])
        self.assertTrue(notification["sensitive_request"])
        self.assertTrue(notification["provider_request"])
        self.assertTrue(notification["invalid_result"])

        compliance = load_probe("tests/test_brechas_condor_21.py").observe()
        self.assertEqual(["OBLIGATORIO", "NO_APLICA"],
                         [x["kind"] for x in compliance["a"]])
        self.assertEqual(["NO_APLICA", "PROHIBIDO"],
                         [x["kind"] for x in compliance["b"]])
        self.assertEqual("REQUIERE_REVISION", compliance["missing"]["kind"])
        self.assertEqual("PENDIENTE", compliance["created"]["state"])
        self.assertEqual("EN_REVISION", compliance["review"]["state"])
        self.assertTrue(compliance["denied"])
        self.assertIsNone(compliance["foreign"])
        self.assertEqual(1, compliance["own_count"])
        self.assertEqual(0, compliance["foreign_count"])

        from scripts import release_evidence as release
        fixtures = load_probe("tests/test_cobertura_condor_08.py")
        result = release.development_transition_safety(
            [fixtures.MIGRATION],
            [{"path": fixtures.MIGRATION, "sha": "a" * 40,
              "source": fixtures.ADD_MIGRATION}],
        )
        self.assertTrue(result["migraciones"])
        self.assertEqual("additive", result["migration_class"])

    def test_falla_cerrado(self):
        """AC-02: no confundir datos alterados o scope ajeno con cumplimiento."""
        compliance = load_probe("tests/test_brechas_condor_21.py").observe()
        self.assertEqual("REQUIERE_REVISION", compliance["unapproved"]["kind"])
        self.assertEqual("REQUIERE_REVISION", compliance["missing"]["kind"])
        self.assertIsNone(compliance["foreign"])
        self.assertEqual(0, compliance["foreign_count"])
        self.assertTrue(compliance["denied"])

        from scripts import release_evidence as release
        fixtures = load_probe("tests/test_cobertura_condor_08.py")
        rejected = release.development_transition_safety(
            [fixtures.MIGRATION],
            [{"path": fixtures.MIGRATION, "sha": "b" * 40,
              "source": fixtures.DROP_MIGRATION}],
        )
        self.assertFalse(rejected["migraciones"])
        self.assertEqual("human", rejected["migration_class"])

    def test_sin_datos_reales(self):
        """AC-03: sin red, secretos, PII ni respaldo de estado de producción."""
        source = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {alias.name.split(".")[0] for n in ast.walk(tree)
                   if isinstance(n, ast.Import) for alias in n.names}
        imports |= {n.module.split(".")[0] for n in ast.walk(tree)
                    if isinstance(n, ast.ImportFrom) and n.module}
        self.assertFalse(imports & {
            "requests", "httpx", "urllib", "socket",
            "pymysql", "mysql", "selenium", "playwright",
        })
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", source)
        self.assertNotIn("DATABASE_" + "URL=", source)
        self.assertTrue(shutil.which("php"), "UNKNOWN: requiere PHP CLI")
        self.assertFalse(set(UNVERIFIABLE) & set(OFFLINE))

    def test_validar_ci_contract(self):
        """AC-04: el agregado Validar falla cerrado; GitHub acredita su HEAD."""
        ci = ROOT / ".github/workflows/ci.yml"
        self.assertTrue(ci.is_file() and not ci.is_symlink())
        source = ci.read_text(encoding="utf-8")
        match = re.search(
            r"(?m)^  validar:\n(?P<job>.*?)(?=^  [a-z][a-z-]*:\n|\Z)",
            source, re.DOTALL,
        )
        self.assertIsNotNone(match, "UNKNOWN: el job Validar está ausente")
        job = match.group("job")
        for required in (
            "name: Validar", "if: always()",
            "needs: [factory-ci, preflight,",
            "PRUEBAS_BASE:", "BACKEND_PHP:", "E2E:",
            '[[ "$FACTORY_CI" == "success" ]]',
            '[[ "$PREFLIGHT" == "success" ]]',
        ):
            with self.subTest(required=required):
                self.assertIn(required, job)
        self.assertNotIn("continue-on-error: true", job)


if __name__ == "__main__":
    unittest.main()

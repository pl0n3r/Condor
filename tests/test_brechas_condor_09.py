#!/usr/bin/env python3
"""Condor #1204: evidencia offline AC-01..AC-04 para D-040-S13..S17.

Pruebas negativas y fuentes locales NO certifican cobertura integral, releases,
seguridad multi-tenant transversal ni producción. La matriz sigue sin cambios.
"""
from __future__ import annotations

import importlib.util
import json
import re
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
DEPENDENCY_GUARD = ROOT / "scripts/verify_symfony_lts_dependency_guard.py"
SCHEMA_POLICY = ROOT / "scripts/live-schema-policy.php"
TENANT_TEST = ROOT / "tests/php/Security/NewModulesTenantIsolationTest.php"
TWIG_LOGIN = ROOT / "templates/security/login.html.twig"
UNKNOWN = "UNKNOWN"

# Requisitos aún pendientes para acreditar la totalidad de cada inciso.
GAPS = {
    "D-040-S13": ("13. Dependencias y vulnerabilidades", (
        "composer_audit", "npm_audit", "dependency_alerts", "high_release_mitigation")),
    "D-040-S14": ("14. Operaciones destructivas y producción", (
        "destructive_denial", "owner_authorization", "verified_backup", "recovery_failure")),
    "D-040-S15": ("15. Seguridad multi-tenant e IDOR", (
        "cross_tenant_read", "cross_tenant_write", "cross_tenant_export", "all_entities_branches")),
    "D-040-S16": ("16. XSS y contenido enriquecido", (
        "twig_escape", "rich_html_sanitization", "react_xss", "attributes_and_urls")),
    "D-040-S17": ("17. Uploads y archivos", (
        "actual_mime", "size_limits", "opaque_paths", "non_execution", "safe_download_headers")),
}


def matrix_rows(document: str) -> dict[str, tuple[str, ...]]:
    """El inventario incompleto, repetido o ambiguo es un error, no verde."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: matriz ausente")
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*`(D-040-S1[3-7])`(?=\s|\W)", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if (key in rows or len(cells) != 5 or not all(cells)
                or cells[0] != f"`{key}` · `ESPECIFICACIONES.md`"
                or cells[1] != GAPS[key][0]
                or cells[2] != "`NO_VERIFICABLE`"
                or key not in cells[3]
                or "Falta evidencia integral" not in cells[3]):
            raise ValueError(f"UNKNOWN: fila duplicada o ambigua {key}")
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: faltan incisos D-040-S13..S17")
    return rows


def missing_proofs(clause: str, evidence: object) -> tuple[str, ...]:
    """Exige pruebas individuales; un resultado CI no las sustituye."""
    if clause not in GAPS:
        raise ValueError("UNKNOWN: inciso no reconocido")
    required = GAPS[clause][1]
    if not isinstance(evidence, dict):
        return required
    return tuple(key for key in required if evidence.get(key) is not True)


def integral_status(clause: str, evidence: object) -> str:
    """Fixtures no autenticadas son insuficientes aun con flags verdaderos."""
    missing_proofs(clause, evidence)
    return UNKNOWN


def load_dependency_guard():
    spec = importlib.util.spec_from_file_location("condor_dependency_guard_s13", DEPENDENCY_GUARD)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: dependency guard ausente")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def schema_decision(classification: str, *, complete: bool) -> dict:
    """Ejercita PHP real sin migrar, desplegar ni usar datos persistentes."""
    payload = {
        "classification": classification,
        "dry_run_valid": complete,
        "allowlist_complete": complete,
        "backup_receipt_verified": complete,
        "post_checks": {
            "service_readiness": complete,
            "schema": complete,
            "smoke": complete,
        },
    }
    completed = subprocess.run(
        ["php", str(SCHEMA_POLICY)], input=json.dumps(payload),
        text=True, capture_output=True, check=False, timeout=10,
    )
    if completed.returncode != 0:
        raise AssertionError("UNKNOWN: live schema policy no ejecutable")
    return json.loads(completed.stdout)


class BrechasCondor09Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cinco filas, dos guardas reales y brechas sin cumplimiento fingido."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(rows), set(GAPS))
        for clause, (title, requirements) in GAPS.items():
            with self.subTest(clause=clause):
                self.assertEqual(rows[clause][1], title)
                self.assertEqual(rows[clause][2], "`NO_VERIFICABLE`")
                self.assertEqual(missing_proofs(clause, {}), requirements)
                self.assertEqual(integral_status(clause, {}), UNKNOWN)

        # S13: guard existente rechaza Symfony fuera de 7.4 LTS.
        guard = load_dependency_guard()
        composer = {"require": {"symfony/framework-bundle": "7.4.*"}}
        lock = {"packages": [{"name": "symfony/framework-bundle",
                              "version": "v8.0.0"}], "packages-dev": []}
        errors = guard.static_errors(composer, lock)
        self.assertTrue(any("Symfony 7.4 LTS" in error for error in errors))
        self.assertEqual(integral_status("D-040-S13", {"composer_audit": True}), UNKNOWN)

        # S14: política PHP real deniega incluso con gates ficticios completos.
        destructive = schema_decision("destructive", complete=True)
        self.assertFalse(destructive["auto_execute"])
        self.assertFalse(destructive["eligible_after_owner_gate"])
        self.assertEqual(destructive["reason"], "destructive_blocked")

        # S15: hay casos parciales, no todas las rutas ni exportaciones.
        tenant_source = TENANT_TEST.read_text(encoding="utf-8")
        self.assertIn("testEmployeeReadMutationAndForeignMembershipAreDenied", tenant_source)
        self.assertIn("testPurchasingRejectsForeignSupplierAndPurchaseAssociations", tenant_source)
        self.assertIn("cross_tenant_export", missing_proofs("D-040-S15", {
            "cross_tenant_read": True, "cross_tenant_write": True}))

        # S16: interpolación aislada no acredita XSS transversal.
        twig = TWIG_LOGIN.read_text(encoding="utf-8")
        self.assertIn("{{ last_username }}", twig)
        self.assertIn("rich_html_sanitization", missing_proofs("D-040-S16", {
            "twig_escape": True}))

        # S17: no existe evidencia integral de MIME, límites y descarga.
        self.assertIn("safe_download_headers", missing_proofs("D-040-S17", {
            "actual_mime": True, "size_limits": True, "opaque_paths": True,
            "non_execution": True}))

    def test_falla_cerrado(self) -> None:
        """AC-02: datos ausentes, duplicados, corruptos y permisos no probados."""
        matrix = MATRIX.read_text(encoding="utf-8")
        row = next(line for line in matrix.splitlines()
                   if line.startswith("| `D-040-S13`"))
        for damaged in (matrix.replace(row, ""), matrix + "\n" + row,
                        matrix.replace(row, "| `D-040-S13` | incompleta |"), ""):
            with self.subTest(damage=len(damaged)):
                with self.assertRaises(ValueError):
                    matrix_rows(damaged)
        with self.assertRaises(ValueError):
            missing_proofs("D-040-S99", {})
        self.assertEqual(schema_decision("unknown", complete=False)["auto_execute"], False)
        self.assertFalse(schema_decision("additive", complete=False)["eligible_after_owner_gate"])
        self.assertFalse(schema_decision("additive", complete=True)["auto_execute"])
        for clause in GAPS:
            for invalid in (None, "success", True, [], {"ci": "success"}):
                with self.subTest(clause=clause, value=str(invalid)):
                    self.assertEqual(missing_proofs(clause, invalid), GAPS[clause][1])
                    self.assertEqual(integral_status(clause, invalid), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: rutas solo lectura y payload de clasificación sintético."""
        for path in (MATRIX, DEPENDENCY_GUARD, SCHEMA_POLICY, TENANT_TEST, TWIG_LOGIN):
            self.assertTrue(path.is_file())
            self.assertTrue(path.is_relative_to(ROOT))
        sample = {"tenant": "synthetic-a", "other_tenant": "synthetic-b",
                  "fake_release": "0.0.0-test", "flags": False}
        self.assertNotEqual(sample["tenant"], sample["other_tenant"])
        self.assertNotIn("@", json.dumps(sample))
        self.assertEqual(integral_status("D-040-S15", sample), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: ni flags todos True, CI success o una prueba parcial dan GREEN."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        for clause, (_, requirements) in GAPS.items():
            with self.subTest(clause=clause):
                self.assertEqual(rows[clause][2], "`NO_VERIFICABLE`")
                complete_claim = {requirement: True for requirement in requirements}
                self.assertEqual(missing_proofs(clause, complete_claim), ())
                self.assertEqual(integral_status(clause, complete_claim), UNKNOWN)
                for requirement in requirements:
                    partial = {**complete_claim, requirement: None, "Validar": "success"}
                    self.assertEqual(missing_proofs(clause, partial), (requirement,))
                    self.assertEqual(integral_status(clause, partial), UNKNOWN)


if __name__ == "__main__":
    unittest.main()

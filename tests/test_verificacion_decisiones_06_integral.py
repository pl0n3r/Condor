#!/usr/bin/env python3
"""#1599: prueba diferencial offline y rechazo de recibos no autenticados.

No contacta hosts, proveedores, correos ni producción. Los testigos PHP
ya existentes acreditan comportamiento local, nunca HEALTH productivo.
"""
from __future__ import annotations

from datetime import datetime
from pathlib import Path
import re
import runpy
import unittest

ROOT = Path(__file__).resolve().parents[1]
PARENT = ROOT / "tests/test_verificacion_decisiones_06.py"
REPORT = ROOT / "docs/verificacion/decisiones-06-integral.md"
TABLE_ID = re.compile(r"^\| (D-\d{3}(?:-S\d{2})?|SPEC-(?:5-5|BASE-[34])) \|")
SHA = re.compile(r"[0-9a-f]{40}\Z")
FIELDS = frozenset({
    "decision_id", "sha", "source_ref", "observed_at", "operator_ref",
    "permission_ref", "result",
})


def parent_contract() -> dict[str, object]:
    if not PARENT.is_file():
        raise AssertionError("UNKNOWN: falta prueba matriz #1549")
    return runpy.run_path(str(PARENT))


def external_evidence_state(envelope: object, expected_sha: object) -> str:
    """La forma del recibo no acredita permiso o firma de la fuente externa.

    REJECTED es un sobre malformado; UNKNOWN un sobre ausente o sin verificador.
    No es posible retornar PASS sin un verificador de autoridad independiente.
    """
    if envelope is None:
        return "UNKNOWN"
    if type(envelope) is not dict or type(expected_sha) is not str:
        return "REJECTED"
    if SHA.fullmatch(expected_sha) is None or set(envelope) != FIELDS:
        return "REJECTED"
    if any(type(k) is not str or type(v) is not str
           for k, v in envelope.items()):
        return "REJECTED"
    if (TABLE_ID.fullmatch("| " + envelope["decision_id"] + " |") is None
            or envelope["sha"] != expected_sha
            or not envelope["source_ref"].startswith("observed:")
            or not envelope["operator_ref"].startswith("owner-approved:")
            or not envelope["permission_ref"].startswith("permission:")
            or envelope["source_ref"] == "observed:"
            or envelope["operator_ref"] == "owner-approved:"
            or envelope["permission_ref"] == "permission:"
            or any(len(value) > 250 or "\\n" in value or "\\r" in value
                   for value in envelope.values())
            or envelope["result"] not in {"PASS", "FAIL", "UNKNOWN"}):
        return "REJECTED"
    try:
        stamp = envelope["observed_at"]
        observed = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
        if (not stamp.endswith("Z") or observed.utcoffset().total_seconds() != 0
                or observed.isoformat(timespec="seconds").replace("+00:00", "Z") != stamp):
            return "REJECTED"
    except (ValueError, TypeError, OverflowError, AttributeError):
        return "REJECTED"
    # No hay firma, consentimiento ni fuente real comprobable en este test.
    return "UNKNOWN"


class VerificacionDecisiones06IntegralTests(unittest.TestCase):
    def test_mapa_18_decisiones_y_brechas_sin_promocion(self) -> None:
        """AC-01: matriz padre verdadera y mapa 18/18 sin pruebas inventadas."""
        parent = parent_contract()
        original = parent["ROWS"]
        self.assertEqual(18, len(original))
        self.assertEqual(18, len({row[0] for row in original}))
        report = REPORT.read_text(encoding="utf-8")
        lines = [line for line in report.splitlines() if TABLE_ID.match(line)]
        self.assertEqual([row[0] for row in original],
                         [TABLE_ID.match(line).group(1) for line in lines])
        self.assertEqual(18, report.count("EXTERNAL_UNKNOWN"))
        self.assertIn("PR #1600", report)
        self.assertIn("0/18", report)
        self.assertNotIn("| CUBIERTO |", report)
        matrix = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        found = parent["matrix_rows"](matrix)
        self.assertEqual(18, len(found))
        for entry, line in zip(original, lines):
            identifier, _title, _heading, source, witness, prior, kind, gap = entry
            with self.subTest(decision=identifier):
                self.assertIn(source, line)
                self.assertIn(witness, line)
                self.assertIn(f"PR #{prior}", line)
                self.assertIn(kind, line)
                self.assertIn(gap, line)
                self.assertIn("**NO_VERIFICABLE**", line)
                self.assertIn("EXTERNAL_UNKNOWN", line)
                self.assertEqual(chr(96) + "NO_VERIFICABLE" + chr(96),
                                 found[identifier][2])
                self.assertTrue((ROOT / source).is_file())
                self.assertTrue((ROOT / witness).is_file())
                self.assertEqual("UNKNOWN", parent["integral_state"](identifier))

    def test_negativos_php_reales_reutilizados(self) -> None:
        """AC-02: ejecutar host no verificado, CSRF y permisos locales."""
        parent = parent_contract()
        for filename, classname in (
            ("test_cobertura_condor_11.py", "CoberturaCondor11Tests"),
            ("test_cobertura_condor_12.py", "CoberturaCondor12Tests"),
            ("test_cobertura_condor_30.py", "CoberturaCondor30Tests"),
        ):
            with self.subTest(witness=filename):
                parent["run_existing_behavior"](filename, classname, "test_falla_cerrado")

    def test_recibos_falsos_y_ausentes_no_son_autorizacion(self) -> None:
        """AC-01/02: SHA y forma no suplantan identidad o autorización."""
        sha = "a" * 40
        candidate = {
            "decision_id": "D-049-S05", "sha": sha,
            "source_ref": "observed:dns-tls", "operator_ref": "owner-approved:external",
            "permission_ref": "permission:scope-read-only",
            "observed_at": "2026-11-07T00:00:00Z", "result": "PASS",
        }
        self.assertEqual("UNKNOWN", external_evidence_state(None, sha))
        self.assertEqual("UNKNOWN", external_evidence_state(candidate, sha))
        for forged in (
            {**candidate, "sha": "b" * 40},
            {**candidate, "decision_id": "D-999"},
            {**candidate, "source_ref": "fixture:synthetic-host"},
            {**candidate, "operator_ref": "fixture:operator"},
            {**candidate, "permission_ref": ""},
            {**candidate, "source_ref": "observed:"},
            {**candidate, "operator_ref": "owner-approved:"},
            {**candidate, "permission_ref": "permission:"},
            {**candidate, "source_ref": "observed:host\\ninjected"},
            {**candidate, "result": "READY"},
            {**candidate, "result": True},
            {**candidate, "observed_at": "2026-02-30T00:00:00Z"},
            {**candidate, "observed_at": "2026-11-07T00:00:00-05:00"},
            {**candidate, "secret": "never"},
            {k: v for k, v in candidate.items() if k != "permission_ref"},
            [], {}, "PASS",
        ):
            with self.subTest(value=str(forged)[:70]):
                self.assertEqual("REJECTED", external_evidence_state(forged, sha))
        self.assertEqual("REJECTED", external_evidence_state(candidate, "not-a-sha"))

    def test_falla_si_se_promueve_matriz_o_duplican_filas(self) -> None:
        """AC-01: alteración o promoción espuria invalida la prueba."""
        parent = parent_contract()
        original = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        target = next(line for line in original.splitlines()
                      if line.startswith("| " + chr(96) + "D-054" + chr(96) + " ·"))
        with self.assertRaises(ValueError):
            parent["matrix_rows"](original + "\n" + target)
        with self.assertRaises(ValueError):
            parent["matrix_rows"](original.replace(
                target, target.replace(
                    chr(96) + "NO_VERIFICABLE" + chr(96),
                    chr(96) + "CUBIERTO" + chr(96), 1), 1))

    def test_ci_descubre_suite_sin_red(self) -> None:
        """AC-03: cobertura local no equivale a producción observada."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("test_verificacion_decisiones_*.py", workflow)
        self.assertIn("unittest discover", workflow)
        report = REPORT.read_text(encoding="utf-8")
        for marker in ("DNS/TLS", "backup", "privacidad", "UNKNOWN", "#1549"):
            self.assertIn(marker, report)


if __name__ == "__main__":
    unittest.main()

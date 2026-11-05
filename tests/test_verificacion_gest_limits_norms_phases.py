#!/usr/bin/env python3
"""Condor #1543: 41 garantías GEST, trazabilidad y negativos fail-closed.

Los testigos previos son parciales; no acreditan flujos integrales ni fase live.
Solo fixtures sintéticos, ninguna llamada de red ni edición de matriz.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs/gestion-empresarial-integral.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
REPORT = ROOT / "docs/verificacion/gest-limits-norms-phases.md"
CHILD = "#1621"

IDS = (
    "GEST-NORM-01",
    "GEST-NORM-02",
    "GEST-NORM-03",
    "GEST-NORM-07",
    "GEST-LIM-01",
    "GEST-LIM-02",
    "GEST-LIM-03",
    "GEST-LIM-04",
    "GEST-LIM-05",
    "GEST-FLUJO-1",
    "GEST-FLUJO-1-P01",
    "GEST-FLUJO-1-P02",
    "GEST-FLUJO-1-P03",
    "GEST-FLUJO-1-P04",
    "GEST-FLUJO-1-P05",
    "GEST-FLUJO-2",
    "GEST-FLUJO-2-P01",
    "GEST-FLUJO-2-P02",
    "GEST-FLUJO-2-P03",
    "GEST-FLUJO-2-P04",
    "GEST-FLUJO-3",
    "GEST-FLUJO-3-P01",
    "GEST-FLUJO-3-P02",
    "GEST-FLUJO-3-P03",
    "GEST-FLUJO-3-P04",
    "GEST-FLUJO-4",
    "GEST-FLUJO-5",
    "GEST-F0",
    "GEST-F1",
    "GEST-F2",
    "GEST-F3",
    "GEST-F4",
    "GEST-F5",
    "GEST-F6",
    "GEST-AC-01",
    "GEST-AC-02",
    "GEST-AC-03",
    "GEST-AC-04",
    "GEST-AC-05",
    "GEST-AC-06",
    "GEST-AC-07",
)
EXPECTED = frozenset(IDS)
WITNESS = {
    "GEST-NORM-01": "tests/test_cobertura_condor_23.py",
    "GEST-NORM-02": "tests/test_cobertura_condor_23.py",
    "GEST-NORM-03": "tests/test_cobertura_condor_24.py",
    "GEST-NORM-07": "tests/test_cobertura_condor_24.py",
    "GEST-LIM-01": "tests/test_cobertura_condor_15.py",
    "GEST-LIM-02": "tests/test_cobertura_condor_16.py",
    "GEST-LIM-03": "tests/test_cobertura_condor_16.py",
    "GEST-LIM-04": "tests/test_cobertura_condor_16.py",
    "GEST-LIM-05": "tests/test_cobertura_condor_16.py",
    "GEST-FLUJO-1": "tests/test_cobertura_condor_13.py",
    "GEST-FLUJO-1-P01": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-1-P02": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-1-P03": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-1-P04": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-1-P05": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-2": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-2-P01": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-2-P02": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-2-P03": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-2-P04": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-3": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-3-P01": "tests/test_cobertura_condor_14.py",
    "GEST-FLUJO-3-P02": "tests/test_cobertura_condor_15.py",
    "GEST-FLUJO-3-P03": "tests/test_cobertura_condor_15.py",
    "GEST-FLUJO-3-P04": "tests/test_cobertura_condor_15.py",
    "GEST-FLUJO-4": "tests/test_cobertura_condor_15.py",
    "GEST-FLUJO-5": "tests/test_cobertura_condor_15.py",
    "GEST-F0": "tests/test_brechas_condor_23.py",
    "GEST-F1": "tests/test_brechas_condor_23.py",
    "GEST-F2": "tests/test_brechas_condor_23.py",
    "GEST-F3": "tests/test_brechas_condor_23.py",
    "GEST-F4": None,
    "GEST-F5": "tests/test_brechas_condor_23.py",
    "GEST-F6": "tests/test_brechas_condor_23.py",
    "GEST-AC-01": "tests/test_cobertura_condor_12.py",
    "GEST-AC-02": "tests/test_cobertura_condor_12.py",
    "GEST-AC-03": "tests/test_cobertura_condor_12.py",
    "GEST-AC-04": "tests/test_brechas_condor_22.py",
    "GEST-AC-05": "tests/test_brechas_condor_22.py",
    "GEST-AC-06": "tests/test_brechas_condor_22.py",
    "GEST-AC-07": "tests/test_brechas_condor_22.py",
}
BT = chr(96)
ROW_RE = re.compile(
    r"^\|\s*" + re.escape(BT) +
    r"(?P<id>GEST-[A-Z0-9-]+)" + re.escape(BT) +
    r"\s*·\s*" + re.escape(BT) +
    r"docs/gestion-empresarial-integral\.md" + re.escape(BT) +
    r"\s*\|\s*(?P<label>[^|]+)\|\s*" + re.escape(BT) +
    r"(?P<state>[^\x60]+)" + re.escape(BT) + r"\s*\|",
    re.MULTILINE,
)


def matrix_rows(raw: str) -> dict[str, tuple[str, str]]:
    """Exigir las 41 claves y estados sin promoción automática."""
    if not isinstance(raw, str) or not raw:
        raise ValueError("UNKNOWN: matriz ilegible")
    result = {}
    for match in ROW_RE.finditer(raw):
        identifier = match.group("id")
        if identifier not in EXPECTED:
            continue
        if identifier in result:
            raise ValueError("UNKNOWN: matriz duplicada " + identifier)
        if match.group("state") != "NO_VERIFICABLE":
            raise ValueError("UNKNOWN: promoción sin prueba " + identifier)
        label = match.group("label").strip()
        if len(label) < 8:
            raise ValueError("UNKNOWN: descripción truncada " + identifier)
        result[identifier] = (label, match.group("state"))
    if set(result) != EXPECTED:
        raise ValueError("UNKNOWN: matriz incompleta")
    return result


def report_rows(raw: str) -> dict[str, str]:
    """Las 41 filas requieren un testigo parcial o ausencia explícita y hoja hija."""
    if not isinstance(raw, str) or raw.count("## Evidencia por fila") != 1:
        raise ValueError("UNKNOWN: sección del reporte ausente")
    section = raw.split("## Evidencia por fila", 1)[1].split(
        "## Negativos", 1
    )[0]
    found = {}
    pattern = re.compile(
        r"^\|\s*" + re.escape(BT) + r"(GEST-[A-Z0-9-]+)" +
        re.escape(BT) + r"\s*\|\s*NO_VERIFICABLE\s*\|"
    )
    for line in section.splitlines():
        if "GEST-" not in line:
            continue
        match = pattern.match(line)
        if match is None:
            raise ValueError("UNKNOWN: fila de reporte malformada")
        identifier = match.group(1)
        if identifier not in EXPECTED or identifier in found:
            raise ValueError("UNKNOWN: fila extra/duplicada")
        witness = WITNESS[identifier]
        required = (witness + "::test_falla_cerrado") if witness else "SIN_TESTIGO_DIRECTO"
        if required not in line or CHILD not in line or len(line.split("|")) != 7:
            raise ValueError("UNKNOWN: testigo o brecha ausente " + identifier)
        found[identifier] = line
    if set(found) != EXPECTED:
        raise ValueError("UNKNOWN: reporte sin las 41 filas")
    return found


def source_anchors(raw: str) -> dict[str, str]:
    """Derivar 41 anclajes de secciones efectivas, no declarar cumplimiento."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: guía ilegible")

    def between(start: str, stop: str) -> str:
        if raw.count(start) != 1 or raw.count(stop) != 1:
            raise ValueError("UNKNOWN: sección de guía ambigua " + start)
        return raw.split(start, 1)[1].split(stop, 1)[0]

    result = {}
    for identifier, title in {
        "GEST-NORM-01": "Límites no negociables",
        "GEST-NORM-02": "Modelo transversal: una sola fuente por concepto",
        "GEST-NORM-03": "Integraciones y fronteras",
        "GEST-NORM-07": "Trazabilidad",
    }.items():
        marker = "## " + title
        if raw.count(marker) != 1:
            raise ValueError("UNKNOWN: norma omitida " + identifier)
        result[identifier] = marker

    limits = between("## Límites no negociables", "## Catálogo de módulos")
    bullets = re.findall(r"(?m)^- (.+)$", limits)
    if len(bullets) != 5:
        raise ValueError("UNKNOWN: límites incompletos")
    for n, bullet in enumerate(bullets, 1):
        result["GEST-LIM-0" + str(n)] = bullet

    flow_titles = {
        1: ("Perfil y cumplimiento", 5),
        2: ("Documento controlado", 4),
        3: ("Riesgo, calidad y mejora", 4),
        4: ("Ciclos laborales futuros (expresamente bloqueados)", 0),
        5: ("Abastecimiento, activos y operación", 0),
    }
    for n, (title, expected_steps) in flow_titles.items():
        heading = f"### {n}. {title}"
        if raw.count(heading) != 1:
            raise ValueError("UNKNOWN: flujo incompleto " + heading)
        result[f"GEST-FLUJO-{n}"] = heading
        if expected_steps:
            segment = raw.split(heading, 1)[1].split("### ", 1)[0]
            steps = re.findall(r"(?m)^([1-5])\. (.+)$", segment)
            if len(steps) != expected_steps:
                raise ValueError("UNKNOWN: pasos de flujo incompletos")
            for index, (step, statement) in enumerate(steps, 1):
                if int(step) != index:
                    raise ValueError("UNKNOWN: flujo mal ordenado")
                result[f"GEST-FLUJO-{n}-P{index:02d}"] = statement

    phases = re.findall(r"(?m)^\| (F[0-6]) \| ([^|]+) \|", raw)
    if len(phases) != 7 or {name for name, _ in phases} != {
        "F" + str(n) for n in range(7)
    }:
        raise ValueError("UNKNOWN: fases incompletas")
    for code, description in phases:
        result["GEST-" + code] = description.strip()

    acceptance = between(
        "## Criterios de aceptación transversales", "## Trazabilidad"
    )
    criteria = re.findall(r"(?m)^- (.+)$", acceptance)
    if len(criteria) != 7:
        raise ValueError("UNKNOWN: aceptación incompleta")
    for index, statement in enumerate(criteria, 1):
        result[f"GEST-AC-{index:02d}"] = statement
    if set(result) != EXPECTED or any(len(v) < 10 for v in result.values()):
        raise ValueError("UNKNOWN: identidad/alcance de guía incorrectos")
    return result


def permit_synthetic(principal: dict, record: dict) -> str:
    """Fixture explicativo, NO autorización productiva."""
    keys = ("tenant", "entity", "site")
    if not isinstance(principal, dict) or not isinstance(record, dict):
        return "DENEGADO"
    if any(not principal.get(k) or not record.get(k) for k in keys):
        return "DENEGADO"
    if any(principal[k] != record[k] for k in keys):
        return "DENEGADO"
    return "LECTURA_SINTETICA"


def preview_evidence(status: str, has_document: bool, reviewed: bool) -> str:
    """La mera carga documental no equivale a CUMPLE."""
    if status not in {"PENDIENTE", "EN_REVISION"}:
        return "REQUIERE_REVISION"
    return "EN_REVISION" if has_document and reviewed else status


def labor_gate(successor: object, d059: object, d063: object) -> str:
    """La decisión B permanece hasta sucesora OWNER y recibos reales."""
    return "ELEGIBLE_SOLO_SINTETICO" if (
        successor == "OWNER_APROBADO" and d059 is True and d063 is True
    ) else "DENEGADO"


class GestLimitsNormsPhasesVerification(unittest.TestCase):
    def test_01_41_ids_and_real_source(self) -> None:
        self.assertEqual(41, len(IDS))
        self.assertEqual(41, len(EXPECTED))
        self.assertEqual(EXPECTED, set(WITNESS))
        self.assertEqual(EXPECTED, set(source_anchors(SOURCE.read_text(encoding="utf-8"))))
        self.assertEqual(EXPECTED, set(matrix_rows(MATRIX.read_text(encoding="utf-8"))))

    def test_02_witnesses_and_honest_report(self) -> None:
        report = REPORT.read_text(encoding="utf-8")
        self.assertEqual(EXPECTED, set(report_rows(report)))
        self.assertIn("41 " + BT + "NO_VERIFICABLE" + BT, report)
        self.assertNotIn("| CUBIERTO |", report)
        self.assertEqual({"GEST-F4"}, {key for key, value in WITNESS.items()
                                       if value is None})
        for identifier, path in WITNESS.items():
            if path is None:
                continue
            with self.subTest(identifier=identifier):
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn(identifier, source)
                self.assertRegex(source, r"(?m)^\s+def test_falla_cerrado\(")

    def test_03_negative_mutations_fail_closed(self) -> None:
        report = REPORT.read_text(encoding="utf-8")
        matrix = MATRIX.read_text(encoding="utf-8")
        example = next(x for x in report.splitlines() if x.startswith(
            "| " + BT + "GEST-NORM-01" + BT + " |"))
        for corrupted in (
            report.replace(example, "", 1),
            report.replace(example, example + "\n" + example, 1),
            report.replace(example, "  " + example, 1),
            report.replace(example, example.replace(CHILD, "#999999"), 1),
            report.replace(example, example.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
        ):
            with self.subTest(change=corrupted[:20]):
                with self.assertRaises(ValueError):
                    report_rows(corrupted)
        matrix_line = next(x for x in matrix.splitlines() if x.startswith(
            "| " + BT + "GEST-NORM-01" + BT + " ·"))
        for change in (
            matrix.replace(matrix_line, "", 1),
            matrix.replace(matrix_line, matrix_line + "\n" + matrix_line, 1),
            matrix.replace(matrix_line, matrix_line.replace(
                BT + "NO_VERIFICABLE" + BT, BT + "CUBIERTO" + BT, 1
            ), 1),
        ):
            with self.assertRaises(ValueError):
                matrix_rows(change)

    def test_04_adversarial_synthetic_boundaries(self) -> None:
        own = {"tenant": "tenant-a", "entity": "entity-a", "site": "site-a"}
        self.assertEqual("LECTURA_SINTETICA", permit_synthetic(own, dict(own)))
        for field in own:
            changed = dict(own)
            changed[field] = "other-" + field
            self.assertEqual("DENEGADO", permit_synthetic(own, changed))
            changed[field] = ""
            self.assertEqual("DENEGADO", permit_synthetic(own, changed))
        self.assertEqual("DENEGADO", permit_synthetic(own, None))
        self.assertEqual("PENDIENTE", preview_evidence("PENDIENTE", True, False))
        self.assertEqual("EN_REVISION", preview_evidence("PENDIENTE", True, True))
        self.assertNotEqual("CUMPLE", preview_evidence("PENDIENTE", True, True))
        self.assertEqual("REQUIERE_REVISION", preview_evidence("", True, True))
        for decision, d059, d063 in (
            (None, True, True), ("B", True, True),
            ("OWNER_APROBADO", None, True),
            ("OWNER_APROBADO", True, False),
        ):
            self.assertEqual("DENEGADO", labor_gate(decision, d059, d063))

    def test_05_ci_claims_and_no_network(self) -> None:
        from scripts.ci_change_classifier import classify
        changed = ["docs/verificacion/gest-limits-norms-phases.md",
                   "tests/test_verificacion_gest_limits_norms_phases.py"]
        selection = classify(changed, "pull_request")
        self.assertTrue(selection.pruebas_base)
        self.assertIn(selection.modo, {"selectivo", "completo"})
        own = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse({alias.name.split(".")[0] for alias in node.names}
                                 & {"requests", "urllib", "socket", "httpx"})
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0],
                                 {"requests", "urllib", "socket", "httpx"})


if __name__ == "__main__":
    unittest.main()

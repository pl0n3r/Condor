#!/usr/bin/env python3
"""#1335: cobertura conductual M01 con PHP real y fixtures sinteticos.

Las filas B/C quedan explicitamente pendientes: no se acredita live, PII,
ni autorizacion juridica para activar RRHH o asistencia.
"""
from __future__ import annotations

import copy
import importlib.util
import json
import re
import socket
import unittest
from functools import lru_cache
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/gestion-empresarial-integral.md"
IDS = (
    "GEST-LIM-02", "GEST-LIM-03", "GEST-LIM-04", "GEST-LIM-05",
    "GEST-M01", "GEST-M01-S02", "GEST-M01-S03", "GEST-M01-S04",
    "GEST-M01-S05", "GEST-M01-S06", "GEST-M01-S07", "GEST-M01-S08",
)
OFFLINE = frozenset(IDS[5:])
REST = {
    "GEST-LIM-02": ("B", "Encendido real de RRHH y asistencia bloqueado por decision B #742 y D-059/D-063."),
    "GEST-LIM-03": ("C", "Documento, regla y CI no acreditan cumplimiento ni produccion."),
    "GEST-LIM-04": ("B", "Segregacion clinica integral y retencion requieren permisos y persistencia autorizados."),
    "GEST-LIM-05": ("C", "Politica juridica transversal de fuente, vigencia y revision; no una regla universal."),
    "GEST-M01": ("B", "El modulo laboral completo y datos reales permanecen desactivados por #742."),
}
HELPERS = {
    "org": "test_m01_estructura.py",
    "leave": "test_m01_novedades.py",
    "http": "test_human_resources_ext_http_api.py",
}


@lru_cache(maxsize=3)
def helper(name: str):
    if name not in HELPERS:
        raise ValueError("UNKNOWN: helper no permitido")
    path = ROOT / "tests" / HELPERS[name]
    if not path.is_file() or path.is_symlink():
        raise AssertionError("UNKNOWN: helper PHP real ausente")
    spec = importlib.util.spec_from_file_location("condor1335_" + name, path)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: helper PHP no importable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CoberturaCondor16Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: siete submodulos A ejercen PHP real, cinco B/C no se promueven."""
        self.assertEqual(12, len(IDS))
        self.assertEqual(set(IDS), OFFLINE | set(REST))
        self.assertFalse(OFFLINE & set(REST))
        self.assertTrue(all(code in {"B", "C", "D"} and len(reason) > 30
                            for code, reason in REST.values()))
        o, l, h = helper("org"), helper("leave"), helper("http")
        structure = o.project(o.fixture())
        absences = l.project(l.fixture())
        self.assertTrue(structure["valid"])
        self.assertTrue(absences["valid"])
        self.assertEqual("REQUIERE_REVISION", structure["state"])
        self.assertEqual("REQUIERE_REVISION", absences["state"])

        with self.subTest(row="GEST-M01-S02", behavior="cargos"):
            positions = {r["ref"]: r for r in structure["positions"]}
            self.assertEqual({"fixture:position/assistant", "fixture:position/manager"}, set(positions))
            self.assertEqual("fixture:area/branch", positions["fixture:position/assistant"]["area_ref"])
            self.assertTrue(positions["fixture:position/assistant"]["without_responsible"])

        with self.subTest(row="GEST-M01-S03", behavior="areas"):
            areas = {r["ref"]: r for r in structure["areas"]}
            self.assertEqual("fixture:area/head", areas["fixture:area/branch"]["parent_ref"])
            self.assertFalse(structure["has_cycles"])

        with self.subTest(row="GEST-M01-S04", behavior="sedes y tenant"):
            mixed = o.fixture()
            foreign = o.fixture("tenant-b", "fixture:company/b")
            mixed["sites"].extend(copy.deepcopy(foreign["sites"]))
            self.assertEqual(structure, o.project(mixed))
            self.assertEqual(["fixture:site/main"], [r["ref"] for r in structure["sites"]])
            self.assertNotIn("tenant-b", json.dumps(o.project(mixed)))

        with self.subTest(row="GEST-M01-S05", behavior="organigrama"):
            cycle = o.fixture()
            cycle["areas"][0]["parent_ref"] = "fixture:area/branch"
            flagged = o.project(cycle)
            self.assertTrue(flagged["has_cycles"])
            self.assertFalse(flagged["can_approve"])
            self.assertFalse(flagged["can_close"])

        with self.subTest(row="GEST-M01-S06", behavior="novedades"):
            requests = {r["ref"]: r for r in absences["requests"]}
            self.assertEqual({"fixture:request/a", "fixture:request/b"}, set(requests))
            self.assertTrue(all(r["overlaps"] for r in requests.values()))
            self.assertFalse(absences["automatically_closed"])

        with self.subTest(row="GEST-M01-S07", behavior="permisos y denegacion"):
            payload = l.fixture()
            payload["requests"][1]["kind"] = "PERMISO"
            result = l.project(payload)
            permission = next(r for r in result["requests"] if r["kind"] == "PERMISO")
            self.assertFalse(permission["automatically_approved"])
            self.assertFalse(permission["automatically_closed"])
            denials = h.execute([
                h.op("GET", forbidden=True), h.op("GET", inactive=True),
                h.op("GET", foreign_owner="tenant-b"),
            ])
            self.assertEqual([{"error": "denied"}] * 3, denials[:3])
            self.assertEqual({"audit": []}, denials[-1])

        with self.subTest(row="GEST-M01-S08", behavior="vacaciones"):
            vacation = next(r for r in absences["requests"] if r["kind"] == "VACACIONES")
            self.assertEqual(5, vacation["requested_days"])
            self.assertTrue(vacation["insufficient_balance"])
            bigger = l.fixture()
            bigger["balances"][0]["days"] = 6
            self.assertFalse(l.project(bigger)["requests"][0]["insufficient_balance"])
            self.assertFalse(absences["automatically_approved"])

    def test_falla_cerrado(self):
        """AC-02: fuente real rechaza PII, estados forzados y scopes invalidos."""
        o, l, h = helper("org"), helper("leave"), helper("http")
        invalid = []
        bad_date = o.fixture()
        bad_date["as_of"] = "2030-02-30"
        invalid.append((o.project, bad_date))
        bad_scope = o.fixture()
        bad_scope["positions"][0]["site_ref"] = "fixture:site/unknown"
        invalid.append((o.project, bad_scope))
        unexpected = o.fixture()
        unexpected["positions"][0]["name"] = "not-allowed"
        invalid.append((o.project, unexpected))
        clinical = l.fixture()
        clinical["requests"][0]["diagnosis"] = "sensitive-not-allowed"
        invalid.append((l.project, clinical))
        approved = l.fixture()
        approved["requests"][0]["state"] = "APROBADO"
        invalid.append((l.project, approved))
        reversed_dates = l.fixture()
        reversed_dates["requests"][0]["starts_on"] = "2026-11-07"
        invalid.append((l.project, reversed_dates))
        bad_balance = l.fixture()
        bad_balance["balances"][0]["days"] = -1
        invalid.append((l.project, bad_balance))
        for fn, data in invalid:
            with self.subTest(kind=fn.__name__, payload=str(data)[-70:]):
                self.assertFalse(fn(data)["valid"])
        failures = h.execute([
            h.op("POST", payload=h.sample(employee_ref="invalid")),
            h.op("POST", payload=h.sample(extra="unsupported")),
            h.op("POST", payload=h.sample(), forbidden=True),
            h.op("POST", payload=h.sample(), foreign_owner="tenant-b"),
            h.op("POST", payload=h.sample(), missing_company=True),
        ])
        self.assertEqual([{"error": "denied"}] * 5, failures[:5])
        self.assertEqual({"audit": []}, failures[-1])

    def test_sin_datos_reales(self):
        """AC-03: no datos reales, conexiones o habilitacion productiva."""
        o, l = helper("org"), helper("leave")
        for fixture in (o.fixture(), l.fixture()):
            data = json.dumps(fixture)
            for forbidden in ("@", "http://", "https://", "-----BEGIN"):
                self.assertNotIn(forbidden, data)
        doc = DOC.read_text(encoding="utf-8")
        self.assertIn("decisión B", doc)
        self.assertIn("solo se admite diseño y fixtures sintéticos", doc)
        for source in (o.CLASS, l.CLASS):
            code = source.read_text(encoding="utf-8").lower()
            for forbidden in ("curl_", "pdo(", "doctrine", "file_get_contents(",
                              "shell_exec(", "httpclient", "entitymanager"):
                self.assertNotIn(forbidden, code)
        with patch.object(socket, "create_connection",
                          side_effect=AssertionError("red prohibida")):
            self.assertTrue(o.project(o.fixture())["valid"])
            self.assertTrue(l.project(l.fixture())["valid"])

    def test_ci_gate_contract(self):
        """AC-04: el clasificador real incluye este test en el CI de PR."""
        from scripts.ci_change_classifier import classify
        choice = classify(["tests/test_cobertura_condor_16.py"], "pull_request")
        self.assertTrue(choice.pruebas_base)
        self.assertIn(choice.modo, {"selectivo", "completo"})
        unknown = classify(["ruta-no-prevista.xyz"], "pull_request")
        self.assertTrue(unknown.validacion_completa)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Condor #1228 — cinco brechas operativas con evidencia local fail-closed.

No cambia la matriz ni acredita producción, aprobaciones, clientes o despachos.
Las fuentes son públicas del propio checkout; los datos de ejemplo son sintéticos.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/modulos-operativos.md"
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
TICK = chr(96)


@dataclass(frozen=True)
class Brecha:
    identifier: str
    text: str
    sources: tuple[tuple[str, tuple[str, ...]], ...]


BRECHAS = (
    Brecha("OPS-M07-PENDIENTE",
           "costo total/unitario por orden y su desglose en la UI dependen de trabajos pendientes.",
           (
               ("src/Application/Production/ProductionCostService.php",
                ("total_cost", "unit_cost", "consumptions")),
               ("frontend/admin/ProductionManagement.tsx",
                ("Costo total:", "Costo por unidad:", "Desglose por material")),
               ("tests/test_production_cost_admin_ui.py",
                ("test_unknown_old_orders_never_invent_a_zero_or_reprice",
                 "test_only_completed_order_read_isolated_from_branch_switches")),
           )),
    Brecha("OPS-M07-PRUEBA",
           "modelar producto, materiales y orden de prueba; revisar consumos y permisos.",
           (
               ("tests/test_production_orders.py",
                ("test_completion_is_atomic_fail_closed_and_requires_production_lite",
                 "test_completion_consumes_bom_materials_and_adds_finished_variant_exactly_once")),
               ("tests/test_production_cost_admin_ui.py",
                ("test_backend_cost_is_validated_before_display_and_bad_data_fails_closed",)),
           )),
    Brecha("OPS-M08-PENDIENTE",
           "la pantalla de descarga con filtros de fecha dentro del administrador está planificada aparte",
           (
               ("frontend/admin/ReportsDownloads.tsx",
                ("branchId", "permissions", "download")),
               ("tests/test_reports_admin_ui.py",
                ("test_integrated_with_authenticated_branch_context",
                 "test_security_contract_and_negative_states")),
           )),
    Brecha("OPS-M08-PRUEBA",
           "validar columnas, rango y aislamiento entre tenants sobre información ficticia.",
           (
               ("tests/test_reports_csv.py",
                ("test_invalid_date_and_range_fail_closed",
                 "test_formula_injection_and_quoted_content")),
               ("src/Http/Controller/ReportsController.php",
                ("CurrentTenantForUser", "BranchAuthorization", "'inventory.view'")),
               ("src/Application/Reporting/TenantCsvReportService.php",
                ("fromDate", "throughDate")),
           )),
    Brecha("OPS-REC-A", "Recorrido A (preparación, no producción)", ()),
)


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """A missing, duplicate or promoted matrix record cannot certify a gap."""
    if not isinstance(raw, str) or not raw.strip():
        raise ValueError("UNKNOWN: empty matrix")
    keys = {item.identifier for item in BRECHAS}
    result = {}
    for line in raw.splitlines():
        if not line.startswith("| " + TICK + "OPS-"):
            continue
        fields = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        head = fields[0] if fields else ""
        key = next((candidate for candidate in keys
                    if head.startswith(TICK + candidate + TICK)), None)
        if key is None:
            continue
        if (key in result or len(fields) != 5
                or not head.startswith(TICK + key + TICK + " · "
                                       + TICK + SPEC + TICK)
                or fields[2] != TICK + "NO_VERIFICABLE" + TICK
                or "Falta vincular para " + key not in fields[3]):
            raise ValueError("UNKNOWN: invalid row " + key)
        result[key] = fields
    if set(result) != keys:
        raise ValueError("UNKNOWN: incomplete matrix")
    return result


def local_evidence(item: Brecha, rows: object, spec: object,
                   sources: object) -> str:
    """Source-level contract only; deployment and functional release stay UNKNOWN."""
    if not isinstance(rows, dict) or not isinstance(spec, str) or not isinstance(sources, dict):
        return UNKNOWN
    row = rows.get(item.identifier)
    if (not isinstance(row, tuple) or len(row) != 5
            or row[2] != TICK + "NO_VERIFICABLE" + TICK
            or not row[1].startswith(item.text)
            or item.text not in spec):
        return UNKNOWN
    if not item.sources:
        return UNKNOWN  # Recorrido A lacks an independently run end-to-end journey.
    for path, tokens in item.sources:
        source = sources.get(path)
        if not isinstance(source, str) or not source.strip():
            return UNKNOWN
        if not all(token in source for token in tokens):
            return UNKNOWN
    return LOCAL


def production_verdict(_item: Brecha, _evidence: object) -> str:
    """A static contract cannot attest real clients, legal authority or Hostinger."""
    return UNKNOWN


class BrechasCondor33Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = (ROOT / MATRIX).read_text(encoding="utf-8")
        cls.spec = (ROOT / SPEC).read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.matrix)
        cls.sources = {
            path: (ROOT / path).read_text(encoding="utf-8")
            for item in BRECHAS for path, _ in item.sources
        }

    def test_contrato_principal(self) -> None:
        """AC-01: all five IDs have a real local guard or an explicit UNKNOWN."""
        self.assertEqual(5, len(BRECHAS))
        self.assertEqual(set(self.rows), {item.identifier for item in BRECHAS})
        for item in BRECHAS:
            with self.subTest(row=item.identifier):
                evidence = local_evidence(item, self.rows, self.spec, self.sources)
                if item.sources:
                    self.assertEqual(LOCAL, evidence)
                else:
                    self.assertEqual("OPS-REC-A", item.identifier)
                    self.assertIn("### Recorrido A", self.spec)
                    self.assertEqual(UNKNOWN, evidence)
                self.assertEqual(UNKNOWN, production_verdict(item, evidence))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing/ambiguous/promoted evidence fails closed."""
        case = BRECHAS[0]
        missing = {k: v for k, v in self.rows.items() if k != case.identifier}
        self.assertEqual(UNKNOWN, local_evidence(case, missing, self.spec, self.sources))
        for item in BRECHAS:
            with self.subTest(missing_row=item.identifier):
                without_row = {key: row for key, row in self.rows.items()
                               if key != item.identifier}
                self.assertEqual(UNKNOWN, local_evidence(
                    item, without_row, self.spec, self.sources))
            for required_path, _tokens in item.sources:
                with self.subTest(missing_source=item.identifier, path=required_path):
                    without_source = {key: value for key, value in self.sources.items()
                                      if key != required_path}
                    self.assertEqual(UNKNOWN, local_evidence(
                        item, self.rows, self.spec, without_source))
        self.assertEqual(UNKNOWN, local_evidence(case, self.rows, "", self.sources))
        self.assertEqual(UNKNOWN, local_evidence(case, self.rows, self.spec, {}))
        path, tokens = case.sources[0]
        altered = dict(self.sources)
        altered[path] = altered[path].replace(tokens[0], "", 1)
        self.assertEqual(UNKNOWN, local_evidence(case, self.rows, self.spec, altered))
        row = next(line for line in self.matrix.splitlines()
                   if line.startswith("| " + TICK + case.identifier + TICK + " · "))
        for damaged in (
            self.matrix + "\n" + row,
            self.matrix.replace(row, "", 1),
            self.matrix.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
        ):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                matrix_rows(damaged)
        self.assertEqual(UNKNOWN, production_verdict(case, "SUCCESS"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: only checked-in source and a synthetic in-memory example."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|httpx|urllib|socket)\b")
        for path in (MATRIX, SPEC, *self.sources):
            self.assertTrue((ROOT / path).resolve().is_relative_to(ROOT.resolve()))
        synthetic = {"tenant": "fixture:tenant-a", "entity": "fixture:entity-a",
                     "branch": "fixture:branch-a", "state": UNKNOWN}
        self.assertTrue(all(v.startswith("fixture:") for k, v in synthetic.items()
                            if k != "state"))
        self.assertEqual(UNKNOWN, synthetic["state"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local source is not evidence of production or full workflow."""
        for item in BRECHAS:
            with self.subTest(row=item.identifier):
                row = self.rows[item.identifier]
                self.assertEqual(TICK + "NO_VERIFICABLE" + TICK, row[2])
                self.assertIn("no se identificó tríada código+prueba+PR fusionado", row[3])
                self.assertEqual(UNKNOWN, production_verdict(
                    item, local_evidence(item, self.rows, self.spec, self.sources)))
                self.assertEqual(UNKNOWN, local_evidence(item, {}, self.spec, self.sources))
        self.assertEqual(UNKNOWN, local_evidence(
            BRECHAS[-1], self.rows, self.spec, self.sources))


if __name__ == "__main__":
    unittest.main()

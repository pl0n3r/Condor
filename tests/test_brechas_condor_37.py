#!/usr/bin/env python3
"""Condor #1232: five offline coverage gaps for company groups and README.

Existing synthetic PHP/HTTP tests are inspected as partial evidence, never
re-executed with credentials or a live application by this suite. No fixture,
document or passing CI attests cross-company readiness or production.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
GUIDE = "docs/modulos-operativos.md"
README = "README.md"
SERVICE = "src/Application/Reporting/TenantCsvReportService.php"
BRANCH = "src/Http/Controller/BranchApiSupport.php"
CUSTOMERS = "tests/test_customers_http_isolation.py"
INVENTORY = "tests/test_inventory_http_negatives.py"
CSV_TEST = "tests/test_reports_csv.py"
README_TEST = "tests/test_readme_contract_adoption.py"
SOURCES = (MATRIX, GUIDE, README, SERVICE, BRANCH, CUSTOMERS,
           INVENTORY, CSV_TEST, README_TEST)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
TICK = chr(96)
CLAUSES = {
    "OPS-REC-D-P1": (
        GUIDE,
        "Modelar cada empresa como tenant separado; no consolidar información saltando permisos.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "OPS-REC-D-P2": (
        GUIDE,
        "Validar roles y acceso al contexto de cada sede; los módulos **Clientes y categorías**, **Inventario y sedes** y **Reportes CSV** deben preservar aislamiento.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "OPS-REC-D-P3": (
        GUIDE,
        "Mantener el control de propietarios/planes separado de la operación diaria.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "OPS-REC-D-P4": (
        GUIDE,
        "Consolidación interempresas, facturación conjunta y operación real están **en construcción**: no ofrecer visibilidad cruzada sin una autorización explícita.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
    ),
    "README-01-01": (
        README,
        "Operational Cockpit — Las insignias se actualizan desde sus fuentes vivas al abrir esta página, sin commits de refresco. La evidencia de Validar, salud, observación de despliegue y decisiones sigue en Actions, Issues y el Orquestador; esta portada no d",
        "Falta prueba independiente de la obligación",
    ),
}
STEPS = (
    "1. Modelar cada empresa como tenant separado; no consolidar información saltando permisos.",
    "2. Validar roles y acceso al contexto de cada sede; los módulos **Clientes y categorías**, **Inventario y sedes** y **Reportes CSV** deben preservar aislamiento.",
    "3. Mantener el control de propietarios/planes separado de la operación diaria.",
    "4. Consolidación interempresas, facturación conjunta y operación real están **en construcción**: no ofrecer visibilidad cruzada sin una autorización explícita.",
)
# Source/test clues establish only partial local behavior, NOT complete product.
EVIDENCE = {
    "OPS-REC-D-P1": {
        BRANCH: ("authorizedBranchScope(", "'tenant' => $tenant"),
        CUSTOMERS: ("$branchB->id()", "assertResponseStatusCodeSame(404)"),
        SERVICE: ("$record['tenant_id'] !== $authenticatedTenantId",),
    },
    "OPS-REC-D-P2": {
        BRANCH: ("$this->authorization->require(", "'tenant' => $tenant"),
        CUSTOMERS: ("BranchRoleAssignment", "assertResponseStatusCodeSame(403)"),
        INVENTORY: ("assertResponseStatusCodeSame(404)",),
        CSV_TEST: ("other-tenant", "test_acceptance"),
    },
    # No source claims owner/plan separation is fully integrated.
    "OPS-REC-D-P3": {},
    "OPS-REC-D-P4": {
        SERVICE: ("REPORT_KIND_UNSUPPORTED", "INVALID_REPORT_RANGE", "TENANT_REQUIRED"),
        CSV_TEST: ('"currency": "COP"', "test_invalid_date_and_range_fail_closed"),
    },
    "README-01-01": {
        README_TEST: ("badge.svg?branch=main", "img.shields.io/github/v/release/pl0n3r/Condor"),
    },
}


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """No missing/duplicated/promoted row or extrapolation from a README."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matriz ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for key, (path, summary, demand) in CLAUSES.items():
        matches = [line for line in raw.splitlines()
                   if line.startswith(f"| {TICK}{key}{TICK} ·")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: fila ausente/duplicada " + key)
        cells = tuple(part.strip() for part in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}{path}{TICK}"
                or cells[1] != summary
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or demand not in cells[3]
                or key not in cells[3]
                or "contraste-cd_ops.md" not in cells[3]
                or cells[4] != "#1"
                or (key.startswith("OPS-") and
                    "no se identificó tríada código+prueba+PR fusionado" not in cells[3])):
            raise ValueError("UNKNOWN: fila inconsistente " + key)
        found[key] = cells
    return found


def section(raw: object, heading: str) -> str:
    if type(raw) is not str:
        return ""
    lines = raw.splitlines()
    if lines.count(heading) != 1:
        return ""
    start = lines.index(heading)
    end = next((i for i in range(start + 1, len(lines))
                if lines[i].startswith("## ") or lines[i].startswith("### ")),
               len(lines))
    return "\n".join(lines[start:end])


def guide_safe(raw: object) -> bool:
    if type(raw) is not str:
        return False
    d = section(raw, "### Recorrido D — Grupo de empresas")
    return bool(
        d and "**Estado general: construcción/pre-live.**" in raw
        and "No hay garantía de que los ocho módulos estén desplegados" in raw
        and "**No activar live**" in raw
        and all(d.splitlines().count(step) == 1 for step in STEPS)
    )


def cockpit_safe(raw: object) -> bool:
    """Static badge URLs verified; real remote updates remain UNKNOWN."""
    area = section(raw, "## Operational Cockpit")
    ci = (
        "[![CI Condor / main](https://github.com/pl0n3r/Condor/actions/"
        "workflows/ci.yml/badge.svg?branch=main)]"
        "(https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)"
    )
    release = (
        "[![Última release Condor](https://img.shields.io/github/v/release/"
        "pl0n3r/Condor?label=release)]"
        "(https://github.com/pl0n3r/Condor/releases)"
    )
    return bool(
        area and area.splitlines().count(ci) == 1
        and area.splitlines().count(release) == 1
        and "sin commits de refresco" in area
        and "esta portada no declara producción validada" in area
        and "https://control.condorapp.com.co/" in area
        and not any(x in area for x in (
            "<!-- factory:status:start -->", "badge.svg?branch=dev",
            "VALIDATED_IN_PRODUCTION", "estado productivo: GREEN",
        ))
    )


def local_evidence(key: object, data: object) -> str:
    """Only a documented gap; existing tests do not prove the full journey."""
    if type(key) is not str or key not in CLAUSES or type(data) is not dict:
        return UNKNOWN
    if set(data) != set(SOURCES) or any(
        type(data[p]) is not str or not data[p] for p in SOURCES
    ):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not guide_safe(data[GUIDE]) or not cockpit_safe(data[README]):
        return UNKNOWN
    for source, snippets in EVIDENCE[key].items():
        if any(snippet not in data[source] for snippet in snippets):
            return UNKNOWN
    return GAP


def operational_state(_key: object, _proof: object = None) -> str:
    """No local test can claim cross-company rights or production verified."""
    return UNKNOWN


class BrechasCondor37Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five unique clauses and partial local evidence or explicit gap."""
        data = sources()
        self.assertEqual(len(CLAUSES), 5)
        self.assertEqual(set(CLAUSES), set(EVIDENCE))
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        self.assertTrue(guide_safe(data[GUIDE]))
        self.assertTrue(cockpit_safe(data[README]))
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(local_evidence(key, data), GAP)
                self.assertEqual(operational_state(key, data), UNKNOWN)
                self.assertEqual(matrix_rows(data[MATRIX])[key][2],
                                 f"{TICK}NO_VERIFICABLE{TICK}")
                for source, needles in EVIDENCE[key].items():
                    self.assertTrue(all(marker in data[source] for marker in needles))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, promoted or stale evidence -> UNKNOWN."""
        data = sources()
        for key in CLAUSES:
            original = next(line for line in data[MATRIX].splitlines()
                            if line.startswith(f"| {TICK}{key}{TICK} ·"))
            variants = (
                data[MATRIX].replace(original, "", 1),
                data[MATRIX] + "\n" + original,
                data[MATRIX].replace(
                    original, original.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                data[MATRIX].replace(
                    original, original.replace("| #1 |", "| #999 |"), 1),
            )
            for mutated in variants:
                with self.subTest(clause=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(mutated)
                    self.assertEqual(
                        local_evidence(key, {**data, MATRIX: mutated}), UNKNOWN)
            document = GUIDE if key.startswith("OPS-") else README
            sentence = (STEPS[int(key[-1]) - 1] if key.startswith("OPS-")
                        else "sin commits de refresco")
            modified = data[document].replace(sentence, "", 1)
            self.assertNotEqual(modified, data[document])
            self.assertEqual(
                local_evidence(key, {**data, document: modified}), UNKNOWN)
            for source, needles in EVIDENCE[key].items():
                missing = data[source].replace(needles[0], "", 1)
                self.assertNotEqual(missing, data[source])
                self.assertEqual(
                    local_evidence(key, {**data, source: missing}), UNKNOWN)
        for invalid in (None, {}, False, {"made": "up"}):
            self.assertEqual(local_evidence("OPS-REC-D-P1", invalid), UNKNOWN)
        self.assertEqual(local_evidence("OPS-REC-D-UNKNOWN", data), UNKNOWN)
        self.assertEqual(local_evidence("README-01-01",
                         {**data, README: data[README].replace(
                             "badge.svg?branch=main", "badge.svg?branch=dev", 1)}),
                         UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no subprocess or network; only checked-in synthetic evidence."""
        own = (ROOT / "tests/test_brechas_condor_37.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        imported = {alias.name.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.Import) for alias in node.names}
        imported |= {node.module.split(".")[0] for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertLessEqual(imported, {"__future__", "ast", "pathlib", "unittest"})
        self.assertTrue(all((ROOT / p).is_file() for p in SOURCES))
        evidence = sources()
        self.assertIn("other-tenant", evidence[CSV_TEST])
        self.assertIn("tenant-b", evidence[CSV_TEST])
        self.assertIn("testCustomerListsAndMutationsAreTenantScoped",
                      evidence[CUSTOMERS])
        self.assertIn("assertResponseStatusCodeSame(404)", evidence[INVENTORY])
        self.assertEqual(operational_state("OPS-REC-D-P2",
                                          {"tenant": "synthetic-a"}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: badges, owner plans and combined billing are NOT attested."""
        data = sources()
        for key in CLAUSES:
            self.assertEqual(local_evidence(key, data), GAP)
            for invented in (
                None, {}, True, {"permission": "allow", "tenant": "fixture"},
                {"currency": "COP", "amount_minor": 12500},
                {"sha": "a" * 40, "ci": "success"},
                {"state": "VALIDATED_IN_PRODUCTION", "owner": "synthetic"},
                {"badge": "green", "release": "latest"},
            ):
                with self.subTest(clause=key):
                    self.assertEqual(operational_state(key, invented), UNKNOWN)
        self.assertTrue(cockpit_safe(data[README]))
        self.assertFalse(cockpit_safe(data[README].replace(
            "badge.svg?branch=main", "badge.svg?branch=dev", 1)))
        self.assertTrue("**en construcción**" in STEPS[3])


if __name__ == "__main__":
    unittest.main()

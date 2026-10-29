#!/usr/bin/env python3
"""Condor #1232: five offline coverage gaps for company groups and README.

The only executable business probe is the repository's pure tenant-scoped PHP
CSV renderer with invented records. It does NOT attest cross-company access,
owner/plan segregation, joint billing, a live badge or production readiness.
"""
from __future__ import annotations

import ast
import csv
from functools import lru_cache
import io
import json
from pathlib import Path
import shutil
import subprocess
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


# No application boot, database, network, real tenants, or credentials.
PHP_PROBE = r'''
require 'src/Application/Reporting/TenantCsvReportService.php';
$service = new \App\Application\Reporting\TenantCsvReportService();
function syntheticRow(string $kind, string $tenant, string $id): array {
    $columns = \App\Application\Reporting\TenantCsvReportService::COLUMNS[$kind];
    $row = array_fill_keys($columns, 'synthetic-fixture');
    $row[$columns[0]] = $id;
    $row['tenant_id'] = $tenant;
    $row['occurred_at'] = '2026-10-02';
    if ($kind === 'sales') {
        $row['currency'] = 'COP';
        $row['total_amount_minor'] = '12500';
    }
    return $row;
}
$out = [];
foreach (['inventory', 'sales', 'purchases'] as $kind) {
    $data = $service->build($kind, 'tenant-synthetic-a', '2026-10-01',
        '2026-10-03', [syntheticRow($kind, 'tenant-synthetic-a', 'own-a'),
                       syntheticRow($kind, 'tenant-synthetic-b', 'foreign-b')]);
    $out[$kind] = $data['body'];
}
function rejected(callable $action): string {
    try { $action(); return 'UNEXPECTED_ALLOW'; }
    catch (\DomainException $ex) { return $ex->getMessage(); }
}
$out['joint_billing_denied'] = rejected(
    fn() => $service->build('consolidated', 'tenant-synthetic-a',
                            '2026-10-01', '2026-10-03', []));
$out['missing_tenant'] = rejected(
    fn() => $service->build('sales', '', '2026-10-01', '2026-10-03', []));
$out['invalid_range'] = rejected(
    fn() => $service->build('sales', 'tenant-synthetic-a',
                            '2026-10-03', '2026-10-01', []));
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'''


@lru_cache(maxsize=1)
def exercise_php() -> dict[str, str]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI ausente")
    run = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
    )
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: PHP sintético falló")
    try:
        parsed = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: salida no es JSON") from exc
    if type(parsed) is not dict:
        raise AssertionError("UNKNOWN: salida PHP inválida")
    return parsed


def csv_safe(proof: object) -> bool:
    if type(proof) is not dict:
        return False
    for kind in ("inventory", "sales", "purchases"):
        body = proof.get(kind)
        if type(body) is not str:
            return False
        lines = list(csv.reader(io.StringIO(body)))
        if len(lines) != 2 or lines[1][0] != "own-a" or "foreign-b" in body:
            return False
    return (
        proof.get("joint_billing_denied") == "REPORT_KIND_UNSUPPORTED"
        and proof.get("missing_tenant") == "TENANT_REQUIRED"
        and proof.get("invalid_range") == "INVALID_REPORT_RANGE"
        and "COP" in proof["sales"] and "12500" in proof["sales"]
    )


def local_evidence(key: object, data: object, proof: object) -> str:
    """At most an explicit gap with partial reproducible local evidence."""
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
    for path, snippets in EVIDENCE[key].items():
        if any(needle not in data[path] for needle in snippets):
            return UNKNOWN
    return GAP if csv_safe(proof) else UNKNOWN


def operational_state(_key: object, _proof: object = None) -> str:
    """No local test can claim cross-company rights or production verified."""
    return UNKNOWN


class BrechasCondor37Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all five IDs, local CSV tenant denial, no silent row."""
        data, proof = sources(), exercise_php()
        self.assertEqual(len(CLAUSES), 5)
        self.assertEqual(set(CLAUSES), set(EVIDENCE))
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        self.assertTrue(csv_safe(proof))
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(local_evidence(key, data, proof), GAP)
                self.assertEqual(operational_state(key, proof), UNKNOWN)
                self.assertEqual(matrix_rows(data[MATRIX])[key][2],
                                 f"{TICK}NO_VERIFICABLE{TICK}")

    def test_falla_cerrado(self) -> None:
        """AC-02: reject absent, ambiguous, promoted and forged evidence."""
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            original = next(line for line in data[MATRIX].splitlines()
                            if line.startswith(f"| {TICK}{key}{TICK} ·"))
            mutations = (
                data[MATRIX].replace(original, "", 1),
                data[MATRIX] + "\n" + original,
                data[MATRIX].replace(
                    original, original.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                data[MATRIX].replace(
                    original, original.replace("| #1 |", "| #999 |"), 1),
            )
            for bad in mutations:
                with self.subTest(clause=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    self.assertEqual(
                        local_evidence(key, {**data, MATRIX: bad}, proof),
                        UNKNOWN,
                    )
            path = GUIDE if key.startswith("OPS-") else README
            marker = (STEPS[int(key[-1]) - 1] if key.startswith("OPS-")
                      else "sin commits de refresco")
            self.assertEqual(
                local_evidence(key, {**data, path: data[path].replace(marker, "", 1)}, proof),
                UNKNOWN,
            )
            for dependency in EVIDENCE[key]:
                self.assertEqual(
                    local_evidence(key, {**data, dependency: ""}, proof), UNKNOWN)
        self.assertEqual(local_evidence("OPS-REC-Z", data, proof), UNKNOWN)
        self.assertEqual(local_evidence("OPS-REC-D-P1", {}, proof), UNKNOWN)
        self.assertEqual(local_evidence("OPS-REC-D-P2", data,
                                        {**proof, "sales": "foreign-b"}), UNKNOWN)
        self.assertEqual(local_evidence("OPS-REC-D-P4", data,
                                        {**proof, "invalid_range": "UNEXPECTED_ALLOW"}),
                         UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: in-memory-only PHP and fictitious tenants; no external I/O."""
        path = ROOT / "tests/test_brechas_condor_37.py"
        tree = ast.parse(path.read_text(encoding="utf-8"))
        imported = {alias.name.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.Import) for alias in node.names}
        imported |= {node.module.split(".")[0] for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imported & {
            "socket", "urllib", "requests", "httpx", "playwright",
            "pymysql", "sqlalchemy", "mysql",
        })
        self.assertTrue(all((ROOT / p).is_file() for p in SOURCES))
        for forbidden in (
            "curl_", "file_get_contents", "file_put_contents", "mysqli",
            "PDO(", "getenv(", "shell_exec", "https://", "http://",
            "DATABASE_URL", "APP_SECRET", "access_token",
        ):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertTrue(csv_safe(exercise_php()))
        self.assertNotIn("foreign-b", json.dumps(exercise_php()))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: badges, offline results and imaginary approvals are not live."""
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            self.assertEqual(local_evidence(key, data, proof), GAP)
            for invented in (
                None, {}, True, {"permission": "allow", "tenant": "fixture"},
                {"currency": "COP", "amount_minor": 12500},
                {"sha": "a" * 40, "ci": "success"},
                {"state": "VALIDATED_IN_PRODUCTION", "owner": "synthetic"},
                {"badge": "green", "release": "latest"},
            ):
                with self.subTest(clause=key):
                    self.assertEqual(operational_state(key, invented), UNKNOWN)
        self.assertEqual(proof["joint_billing_denied"], "REPORT_KIND_UNSUPPORTED")
        self.assertTrue(cockpit_safe(data[README]))
        self.assertFalse(cockpit_safe(
            data[README].replace("badge.svg?branch=main", "badge.svg?branch=dev", 1)))


if __name__ == "__main__":
    unittest.main()

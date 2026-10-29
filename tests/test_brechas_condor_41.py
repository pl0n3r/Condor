#!/usr/bin/env python3
"""Condor #1236: five README 06/07 gaps, synthetic and fail-closed.

Local source and PHP CLI contract checks are not remote tenant verification,
a merged PR, permission to mutate live data, deployment or production GREEN.
The coverage matrix is read-only; a separate task reconciles its verdicts.
"""
from __future__ import annotations

import ast
from copy import deepcopy
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

from scripts.release_identity_guard import VERSION_RE

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT_PARTIAL"
TICK = chr(96)
MATRIX = "docs/matriz-cobertura.md"
README = "README.md"
POLICY = "scripts/live-schema-policy.php"
VERSION = "config/version.php"
GUARD = "scripts/release_identity_guard.py"
COORD = ".github/workflows/coordinacion-trabajo.yml"
CI = ".github/workflows/ci.yml"
SECURITY = ".github/workflows/seguridad.yml"
OBSERVER = ".github/workflows/observar-release.yml"
TENANT_GUARD = "src/Http/Controller/BranchApiSupport.php"
CATALOG = "tests/test_catalog_http_isolation.py"
PRICING = "tests/test_pricing_http_negatives.py"
SOURCES = (MATRIX, README, VERSION, GUARD, COORD, CI, SECURITY, OBSERVER,
           TENANT_GUARD, CATALOG, PRICING, POLICY)
CLAUSES = {
    "README-06-01": ("Ciclo de entrega", "La identidad de release vive en"),
    "README-07-01": ("Calidad y seguridad", "PLAN-AGENTES.md"),
    "README-07-02": ("Calidad y seguridad", "aislamiento multiempresa"),
    "README-07-03": ("Calidad y seguridad", "Migraciones de esquema solo aditivas"),
    "README-07-04": ("Calidad y seguridad", "observación productiva son evidencias distintas"),
}


def read_sources() -> dict[str, str]:
    """Only committed repository text; never credentials or external requests."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: matrix not present")
    result = {}
    for key in CLAUSES:
        prefix = f"| {TICK}{key}{TICK} · {TICK}{README}{TICK} |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicate " + key)
        cells = tuple(c.strip() for c in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}{README}{TICK}"
                or CLAUSES[key][1].casefold() not in cells[1].casefold()
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or "Falta prueba independiente de la obligación" not in cells[3]
                or "PR fusionado vinculado a esa obligación específica" not in cells[3]
                or f"({key})" not in cells[3]
                or "contraste-cd_ops.md" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: malformed/promoted " + key)
        result[key] = cells
    return result


def section(raw: object, heading: str) -> str | None:
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    title = f"## {heading}"
    if lines.count(title) != 1:
        return None
    start = lines.index(title) + 1
    end = next((i for i in range(start, len(lines))
                if lines[i].startswith("## ")), len(lines))
    return "\n".join(lines[start:end])


def safe_policy(payload: object, source: str) -> dict | None:
    """Exercise the real, side-effect-free PHP evaluator with fabricated input."""
    if "function evaluatePolicy(mixed $input)" not in source:
        return None
    php = shutil.which("php")
    if php is None or not (ROOT / POLICY).is_file():
        return None
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(ROOT / POLICY)],
        cwd=ROOT, input=json.dumps(payload), text=True, capture_output=True,
        timeout=15, check=False,
    )
    if result.returncode or result.stderr.strip():
        return None
    try:
        decoded = json.loads(result.stdout)
    except ValueError:
        return None
    return decoded if type(decoded) is dict else None


def additive() -> dict:
    return {
        "classification": "additive", "dry_run_valid": True,
        "allowlist_complete": True, "backup_receipt_verified": True,
        "post_checks": {"service_readiness": True, "schema": True, "smoke": True},
    }


def local_contract(key: object, sources: object, policy_results: object) -> str:
    """Prove a bounded local behavior without promoting an operational verdict."""
    if (key not in CLAUSES or type(sources) is not dict
            or set(sources) != set(SOURCES)
            or any(type(sources[path]) is not str or not sources[path]
                   for path in SOURCES)
            or type(policy_results) is not dict):
        return UNKNOWN
    try:
        matrix_rows(sources[MATRIX])
    except ValueError:
        return UNKNOWN
    heading, phrase = CLAUSES[key]
    part = section(sources[README], heading)
    if part is None or phrase not in part:
        return UNKNOWN

    if key == "README-06-01":
        versions = list(VERSION_RE.finditer(sources[VERSION]))
        valid = (
            len(versions) == 1 and
            re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)",
                         versions[0].group("version")) is not None and
            "config/version.php" in part and
            "README Contract v2" in part and
            "no" in part.lower() and
            "VERSION_RE" in sources[GUARD] and
            "config/version.php" in sources[GUARD] and
            'VERSION_RE.finditer' in sources[GUARD]
        )
    elif key == "README-07-01":
        valid = (
            "AGENTES.md" in part and
            "name: CI Factory v1" in sources[CI] and
            "pl0n3r/factory/.github/workflows/ci.yml@v1" in sources[CI] and
            "name: Validar" in sources[CI] and
            "pl0n3r/factory" in sources[COORD] and
            "ref: v1" in sources[COORD] and
            "github.event.comment.author_association == 'OWNER'" in sources[SECURITY] and
            "ref: v1" in sources[SECURITY]
        )
    elif key == "README-07-02":
        guard = sources[TENANT_GUARD]
        catalog, pricing = sources[CATALOG], sources[PRICING]
        valid = (
            "private function authorizedBranchScope(" in guard and
            "$this->authorization->require(" in guard and
            "$this->currentTenantForUser->resolve($user)" in guard and
            "testTenantCannotReadCreateOrEditForeignCatalog" in catalog and
            "self::assertResponseStatusCodeSame(404)" in catalog and
            "self::assertResponseStatusCodeSame(403)" in catalog and
            "self::assertResponseStatusCodeSame(404)" in pricing and
            "self::assertResponseStatusCodeSame(403)" in pricing
        )
    elif key == "README-07-03":
        good = policy_results.get("complete")
        no_backup = policy_results.get("missing_backup")
        destructive = policy_results.get("destructive")
        wrong = policy_results.get("unknown")
        valid = (
            "function evaluatePolicy(mixed $input)" in sources[POLICY] and
            type(good) is dict and type(no_backup) is dict and
            type(destructive) is dict and type(wrong) is dict and
            good.get("eligible_after_owner_gate") is True and
            good.get("auto_execute") is False and
            good.get("authority") == "owner_gate_required" and
            no_backup.get("eligible_after_owner_gate") is False and
            no_backup.get("reason") == "evidence_incomplete" and
            destructive.get("eligible_after_owner_gate") is False and
            destructive.get("reason") == "destructive_blocked" and
            wrong.get("eligible_after_owner_gate") is False and
            wrong.get("reason") == "unknown_blocked" and
            all(x.get("auto_execute") is False for x in
                (no_backup, destructive, wrong))
        )
    else:
        valid = (
            "badge.svg?branch=main" in sources[README] and
            "esta portada no declara producción validada" in sources[README] and
            "name: Validar" in sources[CI] and
            "name: Observar release de Condor" in sources[OBSERVER] and
            "workflow_dispatch:" in sources[OBSERVER] and
            "SHA completo del merge desplegado" in sources[OBSERVER] and
            "git merge-base --is-ancestor" in sources[OBSERVER] and
            "Sonar" in sources[CI] and
            "seguridad" in part.lower()
        )
    return LOCAL if valid else UNKNOWN


def policy_evidence(sources: dict[str, str]) -> dict:
    """Missing PHP, incomplete output or errors result in UNKNOWN, not success."""
    source = sources.get(POLICY, "")
    valid = additive()
    incomplete = deepcopy(valid)
    incomplete["backup_receipt_verified"] = False
    destructive = deepcopy(valid)
    destructive["classification"] = "destructive"
    unknown = deepcopy(valid)
    unknown["classification"] = "not-a-classification"
    return {
        "complete": safe_policy(valid, source),
        "missing_backup": safe_policy(incomplete, source),
        "destructive": safe_policy(destructive, source),
        "unknown": safe_policy(unknown, source),
    }


def operational_state(_key: object, _evidence: object = None) -> str:
    """Remote tenant coverage, live backup and production are NEVER inferred."""
    return UNKNOWN


class BrechasCondor41Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        data = read_sources()
        checks = policy_evidence(data)
        self.assertEqual(set(CLAUSES), set(matrix_rows(data[MATRIX])))
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(LOCAL, local_contract(key, data, checks))
                self.assertEqual(UNKNOWN, operational_state(key, data))

    def test_falla_cerrado(self) -> None:
        data = read_sources()
        checks = policy_evidence(data)
        for key in CLAUSES:
            row = next(x for x in data[MATRIX].splitlines()
                       if x.startswith(f"| {TICK}{key}{TICK} · "))
            for bad in (
                data[MATRIX].replace(row, "", 1),
                data[MATRIX] + "\n" + row,
                data[MATRIX].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO")),
                data[MATRIX].replace(row, row.replace("| #1 |", "| #999 |")),
            ):
                with self.subTest(clause=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad)
                    self.assertEqual(UNKNOWN, local_contract(
                        key, {**data, MATRIX: bad}, checks))
        tamper = {
            "README-06-01": (VERSION, "'version' =>"),
            "README-07-01": (COORD, "ref: v1"),
            "README-07-02": (TENANT_GUARD, "private function authorizedBranchScope("),
            "README-07-03": (POLICY, "function evaluatePolicy(mixed $input)"),
            "README-07-04": (OBSERVER, "git merge-base --is-ancestor"),
        }
        for key, (path, marker) in tamper.items():
            with self.subTest(clause=key):
                self.assertIn(marker, data[path])
                altered = {**data, path: data[path].replace(marker, "UNKNOWN", 1)}
                self.assertEqual(UNKNOWN, local_contract(key, altered, checks))
        incomplete_policy = {**checks, "missing_backup": None}
        self.assertEqual(UNKNOWN, local_contract(
            "README-07-03", data, incomplete_policy))
        self.assertEqual(UNKNOWN, local_contract("README-99-99", data, checks))
        self.assertEqual(UNKNOWN, local_contract("README-06-01", {}, checks))
        for key in CLAUSES:
            self.assertEqual(UNKNOWN, local_contract(key, data, None))

    def test_sin_datos_reales(self) -> None:
        script = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(script)
        modules = {
            x.name.split(".")[0] for node in ast.walk(tree)
            if isinstance(node, ast.Import) for x in node.names
        }
        modules |= {
            node.module.split(".")[0] for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        }
        self.assertFalse(modules & {"socket", "requests", "httpx", "urllib",
                                    "pymysql", "playwright"})
        self.assertEqual(5, len(CLAUSES))
        self.assertTrue(all((ROOT / p).is_file() for p in SOURCES))
        self.assertEqual("owner_gate_required",
                         policy_evidence(read_sources())["complete"]["authority"])

    def test_estados_unknown_explicitos(self) -> None:
        sources = read_sources()
        checks = policy_evidence(sources)
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(UNKNOWN, operational_state(key, checks))
                self.assertEqual(LOCAL, local_contract(key, sources, checks))
                altered = {
                    **sources,
                    README: sources[README].replace(CLAUSES[key][1], "REMOVED", 1),
                }
                self.assertEqual(UNKNOWN, local_contract(key, altered, checks))
        self.assertEqual(UNKNOWN, operational_state("README-07-04", {
            "validate": "success", "sonar": "success", "production": "GREEN"}))


if __name__ == "__main__":
    unittest.main()

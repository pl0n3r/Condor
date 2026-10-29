#!/usr/bin/env python3
"""Condor #1237: offline, fail-closed contracts for README sections 8 and 9.

Static links and local lockfiles are NOT live GitHub, installation, deployment,
permission, or production evidence. Coverage reconciliation is a separate issue.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_DOCUMENTED_ONLY"
MATRIX = "docs/matriz-cobertura.md"
README = "README.md"
TICK = chr(96)
SOURCES = (
    README, MATRIX, "AGENTES.md", "ESPECIFICACIONES.md",
    "docs/modulos-operativos.md", "decisiones.yml", "datos.yml",
    "composer.lock", "package-lock.json",
    "tests/test_readme_contract_adoption.py",
    "tests/test_release_identity_guard.py", ".github/workflows/ci.yml",
    ".github/workflows/observar-release.yml",
)
CLAUSES = {
    "README-08-01": ("Roadmap y fuentes de verdad", "Plan y prioridad",
                     "Plan y prioridad: Issue #1; Issues y PRs para avance vigente."),
    "README-08-02": ("Roadmap y fuentes de verdad", "Producto",
                     "Producto: ESPECIFICACIONES.md y guía de módulos."),
    "README-08-03": ("Roadmap y fuentes de verdad", "Operación",
                     "Operación: AGENTES.md, decisiones.yml, datos.yml."),
    "README-08-04": ("Roadmap y fuentes de verdad", "Evidencia",
                     "Evidencia: GitHub Actions, Releases y Factory@v1."),
    "README-09-01": ("Desarrollo local", None,
                     "Instala dependencias PHP/Node según los archivos lock. Para esta adopción contractual:"),
}
BASE = "https://github.com/pl0n3r/Condor"


def sources() -> dict[str, str]:
    """Only repository files; no credentials, processes, database or HTTP."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Require exactly one full, unpromoted matrix row per claimed ID."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: invalid coverage matrix")
    found = {}
    for key, (_, _, obligation) in CLAUSES.items():
        prefix = f"| {TICK}{key}{TICK} · {TICK}{README}{TICK} |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicated row " + key)
        cells = tuple(c.strip() for c in matches[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != f"{TICK}{key}{TICK} · {TICK}{README}{TICK}"
                or obligation not in cells[1]
                or cells[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or "Falta prueba independiente de la obligación" not in cells[3]
                or "PR fusionado vinculado a esa obligación específica" not in cells[3]
                or f"({key})" not in cells[3]
                or "contraste-cd_ops.md" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: malformed/promoted row " + key)
        found[key] = cells
    return found


def section(raw: object, heading: str) -> str | None:
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    title = f"## {heading}"
    if lines.count(title) != 1:
        return None
    start = lines.index(title) + 1
    stop = next((i for i in range(start, len(lines))
                 if lines[i].startswith("## ")), len(lines))
    return "\n".join(lines[start:stop])


def local_contract(key: object, data: object) -> str:
    """Check specific repository contracts, never asserting external readiness."""
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or set(data) != set(SOURCES)
            or any(type(data[p]) is not str or not data[p] for p in SOURCES)):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    heading, bullet, _ = CLAUSES[key]
    part = section(data[README], heading)
    if part is None:
        return UNKNOWN
    if key.startswith("README-08-"):
        match = [line for line in part.splitlines()
                 if line.startswith(f"- **{bullet}:**")]
        if len(match) != 1:
            return UNKNOWN
        line = match[0]
    else:
        line = part
        if part.count("Instala dependencias PHP/Node según los archivos lock.") != 1:
            return UNKNOWN
    if key == "README-08-01":
        ok = all(x in line for x in (
            f"[Issue #1]({BASE}/issues/1)",
            f"[Issues]({BASE}/issues)", f"[PRs]({BASE}/pulls)",
            "para avance vigente.",
        ))
    elif key == "README-08-02":
        specs_link = f"[{TICK}ESPECIFICACIONES.md{TICK}]({BASE}/blob/main/ESPECIFICACIONES.md)"
        ok = (specs_link in line
              and f"[guía de módulos]({BASE}/blob/main/docs/modulos-operativos.md)" in line
              and data["ESPECIFICACIONES.md"].startswith("# Condor")
              and "# Guía de módulos operativos" in data["docs/modulos-operativos.md"])
    elif key == "README-08-03":
        ok = all(f"({BASE}/blob/main/{p})" in line
                 for p in ("AGENTES.md", "decisiones.yml", "datos.yml"))
        try:
            decisions = json.loads(data["decisiones.yml"])
            personal = json.loads(data["datos.yml"])
        except (ValueError, TypeError):
            return UNKNOWN
        ok = (ok and "# Condor" in data["AGENTES.md"]
              and type(decisions) is dict and type(decisions.get("decisions")) is list
              and type(personal) is dict and personal.get("project") == "pl0n3r/Condor"
              and personal.get("phase") == "construccion")
    elif key == "README-08-04":
        ok = all(x in line for x in (
            f"[GitHub Actions]({BASE}/actions)",
            f"[Releases]({BASE}/releases)",
            "[Factory@v1](https://github.com/pl0n3r/factory/tree/v1)",
        ))
        ok = (ok and "name: CI Condor" in data[".github/workflows/ci.yml"]
              and "name: Validar" in data[".github/workflows/ci.yml"]
              and "name: Observar release de Condor" in data[".github/workflows/observar-release.yml"])
    else:
        ok = all(x in line for x in (
            "Instala dependencias PHP/Node según los archivos lock.",
            "python3 tests/test_readme_contract_adoption.py",
            "python3 tests/test_release_identity_guard.py",
            TICK * 3 + "bash", TICK * 3,
        ))
        try:
            composer = json.loads(data["composer.lock"])
            node = json.loads(data["package-lock.json"])
        except (ValueError, TypeError):
            return UNKNOWN
        ok = (ok and type(composer.get("packages")) is list
              and type(node.get("packages")) is dict
              and "class ReadmeContractAdoptionTests" in data["tests/test_readme_contract_adoption.py"]
              and "class ReleaseIdentityGuardTests" in data["tests/test_release_identity_guard.py"])
    return LOCAL if ok else UNKNOWN


def operational_state(_key: object, _evidence: object = None) -> str:
    """No synthetic document or alleged API result proves live observability."""
    return UNKNOWN


class BrechasCondor42Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five exact rows and five bounded local checks."""
        data = sources()
        self.assertEqual(len(CLAUSES), 5)
        self.assertEqual(set(matrix_rows(data[MATRIX])), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(gap=key):
                self.assertEqual(local_contract(key, data), LOCAL)
                self.assertEqual(operational_state(key, data), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, prematurely promoted or fake proof fails."""
        data = sources()
        raw = data[MATRIX]
        for key in CLAUSES:
            line = next(x for x in raw.splitlines()
                        if x.startswith(f"| {TICK}{key}{TICK} ·"))
            for bad in (
                raw.replace(line, "", 1),
                raw + "\n" + line,
                raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(line, line.replace("PR fusionado vinculado", "PR no verificable"), 1),
            ):
                with self.subTest(gap=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(bad)
                    tampered = dict(data)
                    tampered[MATRIX] = bad
                    self.assertEqual(local_contract(key, tampered), UNKNOWN)
            altered = dict(data)
            altered[README] = data[README].replace(
                f"## {CLAUSES[key][0]}", f"## {CLAUSES[key][0]}-retirado", 1)
            self.assertEqual(local_contract(key, altered), UNKNOWN)
            altered = dict(data)
            altered[README] += f"\n## {CLAUSES[key][0]}\n"
            self.assertEqual(local_contract(key, altered), UNKNOWN)
            if key.startswith("README-08-"):
                bullet = CLAUSES[key][1]
                item = next(x for x in data[README].splitlines()
                            if x.startswith(f"- **{bullet}:**"))
                without_bullet = dict(data)
                without_bullet[README] = data[README].replace(item, "", 1)
                self.assertEqual(local_contract(key, without_bullet), UNKNOWN)
            else:
                without_command = dict(data)
                without_command[README] = data[README].replace(
                    "python3 tests/test_release_identity_guard.py", "", 1)
                self.assertEqual(local_contract(key, without_command), UNKNOWN)
        self.assertEqual(local_contract("README-00-00", data), UNKNOWN)
        self.assertEqual(local_contract("README-08-01", None), UNKNOWN)
        self.assertEqual(local_contract("README-08-01", {}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: this suite uses read-only local text; no network or PII."""
        tree = ast.parse((ROOT / "tests/test_brechas_condor_42.py").read_text(encoding="utf-8"))
        imported: set[str] = set()
        for item in ast.walk(tree):
            if isinstance(item, ast.Import):
                imported.update(x.name.split(".")[0] for x in item.names)
            elif isinstance(item, ast.ImportFrom):
                imported.add((item.module or "").split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "json", "pathlib", "unittest"})
        data = sources()
        self.assertEqual(local_contract("README-08-03", data), LOCAL)
        for invalid in ("", "null", '{"phase":"live"}', "invalid-json"):
            other = dict(data)
            other["datos.yml"] = invalid
            self.assertEqual(local_contract("README-08-03", other), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: fake PR, green CI, release or user claims never certify live."""
        data = sources()
        claims = (None, {}, {"merged": True}, {"ci": "success"},
                  {"deployment": "success", "sha": "synthetic"},
                  {"owner": "fixture", "approved": True},
                  {"production": True}, {"tenant": "fixture-a"})
        for key in CLAUSES:
            with self.subTest(gap=key):
                for claim in claims:
                    self.assertEqual(operational_state(key, claim), UNKNOWN)
        for key, path, mark in (
            ("README-08-02", "ESPECIFICACIONES.md", "# Condor"),
            ("README-08-04", ".github/workflows/ci.yml", "name: Validar"),
            ("README-09-01", "package-lock.json", '"packages"'),
        ):
            changed = dict(data)
            changed[path] = data[path].replace(mark, "", 1)
            self.assertEqual(local_contract(key, changed), UNKNOWN)
        tampered = dict(data)
        tampered[README] = data[README].replace(
            "Instala dependencias PHP/Node según los archivos lock.",
            "Instala sin lockfile", 1)
        self.assertEqual(local_contract("README-09-01", tampered), UNKNOWN)


if __name__ == "__main__":
    unittest.main()

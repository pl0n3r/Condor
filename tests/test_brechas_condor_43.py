#!/usr/bin/env python3
"""Condor #1238: offline, fail-closed contracts for five README coverage gaps.

Source-level evidence is DOCUMENTED_ONLY, not proof of external control planes,
CI on a remote SHA, a merged PR, deployment, or production GREEN. The five
matrix rows stay NO_VERIFICABLE until separately reconciled with merged evidence.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
DOCUMENTED = "DOCUMENTED_ONLY"
TICK = chr(96)
MATRIX = "docs/matriz-cobertura.md"
README = "README.md"
AGENTS = "AGENTES.md"
CI = ".github/workflows/ci.yml"
PHPSTAN = "phpstan.neon.dist"
RECTOR = "rector.php"
PROJECT = "readme/project.json"
TOOLING_TEST = "tests/test_static_analysis_tooling.py"
README_TEST = "tests/test_readme_contract_adoption.py"
FILES = (MATRIX, README, AGENTS, CI, PHPSTAN, RECTOR, PROJECT,
         TOOLING_TEST, README_TEST)

CLAUSES = {
    "README-09-02": ("Desarrollo local",
                     "Antes de entregar cambios PHP/tooling, Condor exige también PHPStan y Rector según AGENTES.md."),
    "README-10-01": ("Mapa de la fábrica",
                     "Factory: governance/kit y contratos compartidos."),
    "README-10-02": ("Mapa de la fábrica",
                     "ControlBot: control plane privado."),
    "README-10-03": ("Mapa de la fábrica",
                     "FactoryRunner: execution plane autónomo."),
    "README-10-04": ("Mapa de la fábrica",
                     "AutoFactory: herramienta local/manual separada."),
}
ROLES = {
    "Factory": ("governance/kit y contratos compartidos", "governance"),
    "ControlBot": ("control plane privado", "control"),
    "FactoryRunner": ("execution plane autónomo", "execution"),
    "AutoFactory": ("herramienta local/manual separada", "manual"),
}
# Synthetic fixtures, not live account permissions or integration calls.
ACTIONS = {
    "governance": "Factory",
    "shared_contract": "Factory",
    "control_plane": "ControlBot",
    "execution_plane": "FactoryRunner",
    "manual_local": "AutoFactory",
}


def sources() -> dict[str, str]:
    """Read only committed text, without network, credentials or subprocesses."""
    return {file: (ROOT / file).read_text(encoding="utf-8") for file in FILES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: missing matrix")
    result = {}
    for key, (_, phrase) in CLAUSES.items():
        prefix = "| " + TICK + key + TICK + " · " + TICK + README + TICK + " |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicate " + key)
        cells = tuple(column.strip() for column in matches[0].strip("|").split("|"))
        if (
            len(cells) != 5
            or cells[0] != TICK + key + TICK + " · " + TICK + README + TICK
            or phrase.casefold() not in cells[1].casefold()
            or cells[2] != TICK + "NO_VERIFICABLE" + TICK
            or "Falta prueba independiente de la obligación" not in cells[3]
            or "PR fusionado vinculado a esa obligación específica" not in cells[3]
            or "(" + key + ")" not in cells[3]
            or "contraste-cd_ops.md" not in cells[3]
            or cells[4] != "#1"
        ):
            raise ValueError("UNKNOWN: malformed/promoted " + key)
        result[key] = cells
    return result


def section(raw: object, heading: str) -> str | None:
    if type(raw) is not str:
        return None
    lines = raw.splitlines()
    title = "## " + heading
    if lines.count(title) != 1:
        return None
    start = lines.index(title) + 1
    end = next((i for i in range(start, len(lines))
                if lines[i].startswith("## ")), len(lines))
    return "\n".join(lines[start:end])


def roles_from_map(raw: object) -> dict[str, str] | None:
    """Require one unambiguous declaration per role, no extra principal."""
    block = section(raw, "Mapa de la fábrica")
    if block is None:
        return None
    lines = [line.strip() for line in block.splitlines()
             if line.strip().startswith("- **")]
    if len(lines) != 5:
        return None
    parsed = {}
    for line in lines:
        match = re.fullmatch(r"- \*\*(.+?):\*\* (.+)\.", line)
        if match is None:
            return None
        name, description = match.groups()
        if name in parsed:
            return None
        parsed[name] = description
    if set(parsed) != set(ROLES) | {"Condor / GrindFlow / BRVTAL"}:
        return None
    if parsed["Condor / GrindFlow / BRVTAL"] != "productos independientes":
        return None
    if any(parsed[role] != expected[0] for role, expected in ROLES.items()):
        return None
    return parsed


def route_synthetic(action: object, readme: object) -> str:
    """Model *documentation ownership* only; never grant an actual capability."""
    if type(action) is not str or roles_from_map(readme) is None:
        return UNKNOWN
    role = ACTIONS.get(action)
    if role is None or role not in ROLES:
        return UNKNOWN
    return role


def source_contract(key: object, data: object) -> str:
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or set(data) != set(FILES)
            or any(type(data[file]) is not str or not data[file] for file in FILES)):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    heading, phrase = CLAUSES[key]
    block = section(data[README], heading)
    if block is None or phrase not in block:
        return UNKNOWN

    if key == "README-09-02":
        agents, ci = data[AGENTS], data[CI]
        ok = all((
            "PHPStan nivel 8" in agents,
            "vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G" in agents,
            "vendor/bin/rector process --dry-run --no-progress-bar" in agents,
            "name: Análisis estático PHP" in ci,
            "name: Rector dry-run" in ci,
            "vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G" in ci,
            "vendor/bin/rector process --dry-run --no-progress-bar" in ci,
            "phpstan-baseline.neon" in data[PHPSTAN],
            "level: 8" in data[PHPSTAN],
            "RectorConfig::configure()" in data[RECTOR],
            "test_ci_runs_phpstan_and_defers_rector_gate" in data[TOOLING_TEST],
            "test_agents_documents_static_analysis_commands" in data[TOOLING_TEST],
        ))
    else:
        parsed = roles_from_map(data[README])
        try:
            project = json.loads(data[PROJECT])
        except (ValueError, TypeError):
            return UNKNOWN
        if type(project) is not dict:
            return UNKNOWN
        mappings = {
            "README-10-01": ("governance", "Factory"),
            "README-10-02": ("control_plane", "ControlBot"),
            "README-10-03": ("execution_plane", "FactoryRunner"),
            "README-10-04": ("manual_local", "AutoFactory"),
        }
        action_name, expected_role = mappings[key]
        ok = all((
            parsed is not None,
            route_synthetic(action_name, data[README]) == expected_role,
            project.get("role") ==
                "Producto independiente de la fábrica, gobernado por Factory y operado mediante su propio runtime.",
            "Factory v1 provee el núcleo común" in data[AGENTS],
            "pl0n3r/factory/.github/workflows/ci.yml@v1" in data[CI],
            "test_factory_map_preserves_roles" in data[README_TEST],
        ))
    return DOCUMENTED if ok else UNKNOWN


def operational_state(_key: object, _candidate: object = None) -> str:
    """CI evidence, deployment and external planes must be separately verified."""
    return UNKNOWN


class BrechasCondor43Tests(unittest.TestCase):
    def test_contrato_principal(self):
        data = sources()
        rows = matrix_rows(data[MATRIX])
        self.assertEqual(set(CLAUSES), set(rows))
        roles = roles_from_map(data[README])
        self.assertIsNotNone(roles)
        for key in CLAUSES:
            with self.subTest(clause=key):
                heading, phrase = CLAUSES[key]
                self.assertIn(phrase, section(data[README], heading))
                self.assertEqual(DOCUMENTED, source_contract(key, data))
                self.assertEqual(UNKNOWN, operational_state(key, data))
        for action, owner in ACTIONS.items():
            with self.subTest(action=action):
                self.assertEqual(owner, route_synthetic(action, data[README]))

    def test_falla_cerrado(self):
        data = sources()
        for key in CLAUSES:
            original = next(line for line in data[MATRIX].splitlines()
                            if line.startswith("| " + TICK + key + TICK + " · "))
            for damaged in (
                data[MATRIX].replace(original, "", 1),
                data[MATRIX] + "\n" + original,
                data[MATRIX].replace(original,
                                     original.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                data[MATRIX].replace(original,
                                     original.replace("| #1 |", "| #999 |"), 1),
            ):
                with self.subTest(clause=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(damaged)
                    self.assertEqual(UNKNOWN, source_contract(
                        key, {**data, MATRIX: damaged}))
            phrase = CLAUSES[key][1]
            self.assertEqual(UNKNOWN, source_contract(
                key, {**data, README: data[README].replace(phrase, "AMBIGUOUS", 1)}))
        mutations = {
            "README-09-02": (CI, "name: Rector dry-run"),
            "README-10-01": (PROJECT, "gobernado por Factory"),
            "README-10-02": (README_TEST, "test_factory_map_preserves_roles"),
            "README-10-03": (AGENTS, "Factory v1 provee el núcleo común"),
            "README-10-04": (CI, "pl0n3r/factory/.github/workflows/ci.yml@v1"),
        }
        for key, (file, marker) in mutations.items():
            self.assertIn(marker, data[file])
            self.assertEqual(UNKNOWN, source_contract(
                key, {**data, file: data[file].replace(marker, "MISSING", 1)}))
        for bad in (None, {}, {"ci": "success"}, {"production": "GREEN"}):
            self.assertEqual(UNKNOWN, source_contract("README-10-01", bad))
        for bad in (None, "", "delete-production", "control_plane "):
            self.assertEqual(UNKNOWN, route_synthetic(bad, data[README]))
        self.assertEqual(UNKNOWN, route_synthetic("control_plane", data[README] +
                         "\n- **ControlBot:** control plane privado."))
        self.assertEqual(UNKNOWN, source_contract("README-99-01", data))

    def test_sin_datos_reales(self):
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        names = {alias.name.split(".")[0] for node in ast.walk(tree)
                 if isinstance(node, ast.Import) for alias in node.names}
        names |= {node.module.split(".")[0] for node in ast.walk(tree)
                  if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(names & {"requests", "httpx", "socket", "urllib",
                                  "subprocess", "pymysql", "playwright", "os"})
        self.assertEqual(5, len(CLAUSES))
        self.assertTrue(all((ROOT / file).is_file() for file in FILES))
        self.assertEqual("construction", json.loads(sources()[PROJECT])["phase"])
        self.assertEqual(set(ACTIONS), {"governance", "shared_contract",
                                       "control_plane", "execution_plane",
                                       "manual_local"})

    def test_estados_unknown_explicitos(self):
        data = sources()
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(DOCUMENTED, source_contract(key, data))
                self.assertEqual(UNKNOWN, operational_state(key, {
                    "Validar": "success", "CodeQL": "success",
                    "SonarCloud": "success", "production": "GREEN"}))
                self.assertEqual(TICK + "NO_VERIFICABLE" + TICK,
                                 matrix_rows(data[MATRIX])[key][2])
        self.assertEqual(UNKNOWN, route_synthetic("deploy", data[README]))
        self.assertEqual(UNKNOWN, route_synthetic("manual_local", {}))
        self.assertEqual(UNKNOWN, operational_state("README-10-03"))


if __name__ == "__main__":
    unittest.main()

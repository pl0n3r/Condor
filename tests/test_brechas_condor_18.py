#!/usr/bin/env python3
"""Condor #1213: cinco brechas D-048/D-049, evidencia offline y fail-closed.

El contraste de archivos locales nunca acredita funcionalidad integral,
seguridad productiva, desplegado, ni una triada código/test/PR fusionado.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
DOCUMENTED = "BRECHA_DOCUMENTADA"
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-cd_d3.md"
SPEC = "ESPECIFICACIONES.md"
OWNER = "frontend/admin/PlatformOwnerApp.tsx"
RESOLVER = "src/Infrastructure/Tenancy/TenantResolver.php"
OWNER_TEST = "tests/Unit/PlatformOwnerNavigationTest.php"
RESOLVER_TEST = "tests/php/Infrastructure/Tenancy/TenantResolverTest.php"
SOURCES = (MATRIX, CONTRAST, SPEC, OWNER, RESOLVER, OWNER_TEST, RESOLVER_TEST)

# (título exacto de matriz, sección de norma, fuente parcial, marcador de fuente,
#  test parcial según el contraste). Ninguno acredita la decisión completa.
GAPS = {
    "D-048": (
        "Administración unificada y contexto operativo del propietario de plataforma",
        "### D-048 — Administración unificada y contexto operativo del propietario de plataforma",
        OWNER, "platformOwnerContextPath", OWNER_TEST,
    ),
    "D-048-S02": (
        "Progreso funcional visible",
        "#### Progreso funcional visible",
        OWNER, "FunctionalSignalsPanel", OWNER_TEST,
    ),
    "D-049-S01": (
        "Identidad pública del tenant",
        "#### Identidad pública del tenant",
        RESOLVER, "'tenant_slug'", RESOLVER_TEST,
    ),
    "D-049-S04": (
        "Host y seguridad",
        "#### Host y seguridad",
        RESOLVER, "'verified' => true", RESOLVER_TEST,
    ),
    "D-049-S06": (
        "Administración",
        "#### Administración",
        RESOLVER, "public function resolve(Request $request): ?Tenant", RESOLVER_TEST,
    ),
}
NORMATIVE = {
    "D-048": ("ROLE_PLATFORM_OWNER", "barra de contexto", "actor real"),
    "D-048-S02": ("read-only", "placeholders", "permisos y contexto"),
    "D-049-S01": ("TenantResolver", "host desconocido", "tenant"),
    "D-049-S04": ("Host Header Injection", "HTTPS", "permisos"),
    "D-049-S06": ("dominio configurado", "Super Admin", "desactivación"),
}


def local_sources() -> dict[str, str]:
    """Solo archivos versionados, sin HTTP, navegador, DB ni shell."""
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in SOURCES}


def rows(raw: str, kind: str) -> dict[str, tuple[str, ...]]:
    """Una fila exacta para cada claim; cualquier ambigüedad es UNKNOWN."""
    if kind not in ("matrix", "contrast"):
        raise ValueError("UNKNOWN: unsupported source kind")
    found: dict[str, tuple[str, ...]] = {}
    for key, (title, _heading, source, _marker, test) in GAPS.items():
        prefix = f"| `{key}` ·" if kind == "matrix" else f"| {key} |"
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing or duplicate " + key)
        cells = tuple(cell.strip() for cell in matches[0].strip("|").split("|"))
        if kind == "matrix":
            if (len(cells) != 5
                    or cells[0] != f"`{key}` · `ESPECIFICACIONES.md`"
                    or cells[1] != title
                    or cells[2] != "`NO_VERIFICABLE`"
                    or f"Falta evidencia integral {key}" not in cells[3]
                    or "tríada código/test/PR MERGED" not in cells[3]
                    or cells[4] != "#1"):
                raise ValueError("UNKNOWN: invalid matrix " + key)
        elif (len(cells) != 6 or cells[0] != key
              or cells[1] != "NO_VERIFICABLE"
              or cells[2] != source or cells[3] != test
              or cells[4] != "—"
              or "Falta acreditar integralmente" not in cells[5]
              or key not in cells[5]):
            raise ValueError("UNKNOWN: invalid contrast " + key)
        found[key] = cells
    return found


def subsection(raw: str, key: str) -> str:
    """Delimita D-048 o D-049 antes de buscar subsecciones homónimas."""
    parent = "### D-048 —" if key.startswith("D-048") else "### D-049 —"
    matches = list(re.finditer(r"(?m)^" + re.escape(parent) + r"[^\n]*$", raw))
    if len(matches) != 1:
        return ""
    tail = raw[matches[0].start():]
    next_parent = re.search(r"(?m)^### ", tail.split("\n", 1)[1])
    if next_parent:
        tail = tail[:len(tail.split("\n", 1)[0]) + 1 + next_parent.start()]
    heading = GAPS[key][1]
    if key in ("D-048",):
        return tail
    boundaries = [i for i, line in enumerate(tail.splitlines())
                  if line == heading]
    if len(boundaries) != 1:
        return ""
    lines = tail.splitlines()
    start = boundaries[0]
    end = next((i for i in range(start + 1, len(lines))
                if lines[i].startswith("#### ") or lines[i].startswith("### ")),
               len(lines))
    return "\n".join(lines[start:end])


def classify(key: str, data: dict[str, str]) -> str:
    """Documenta la brecha parcial, sin elevarla a cobertura real."""
    if key not in GAPS or any(not data.get(path) for path in SOURCES):
        return UNKNOWN
    try:
        matrix = rows(data[MATRIX], "matrix")
        contrast = rows(data[CONTRAST], "contrast")
    except ValueError:
        return UNKNOWN
    title, heading, source, marker, test = GAPS[key]
    clause = subsection(data[SPEC], key)
    if (matrix[key][1] != title or contrast[key][2] != source
            or heading not in clause
            or any(part not in clause for part in NORMATIVE[key])
            or marker not in data[source]
            or not data[test].strip()):
        return UNKNOWN
    return DOCUMENTED


def operational_state(_key: object, _claim: object = None) -> str:
    """La presencia de código parcial o una declaración no certifica producción."""
    return UNKNOWN


class BrechasCondor18Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cinco decisiones reconocidas sin fabricar CUBIERTO."""
        data = local_sources()
        self.assertEqual(set(rows(data[MATRIX], "matrix")), set(GAPS))
        self.assertEqual(set(rows(data[CONTRAST], "contrast")), set(GAPS))
        for key in GAPS:
            with self.subTest(brecha=key):
                self.assertEqual(DOCUMENTED, classify(key, data))
                self.assertEqual("`NO_VERIFICABLE`",
                                 rows(data[MATRIX], "matrix")[key][2])
                self.assertEqual(UNKNOWN, operational_state(key, data))

    def test_falla_cerrado(self) -> None:
        """AC-02: sin filas, norma o marcador local solo UNKNOWN."""
        data = local_sources()
        for filename, kind in ((MATRIX, "matrix"), (CONTRAST, "contrast")):
            for key in GAPS:
                prefix = f"| `{key}` ·" if kind == "matrix" else f"| {key} |"
                row = next(line for line in data[filename].splitlines()
                           if line.startswith(prefix))
                for tampered in (data[filename].replace(row, "", 1),
                                 data[filename] + "\n" + row,
                                 data[filename].replace(
                                     row, row.replace("NO_VERIFICABLE",
                                                      "CUBIERTO", 1), 1)):
                    with self.subTest(source=kind, brecha=key):
                        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                            rows(tampered, kind)
        for key, (_title, heading, path, marker, _test) in GAPS.items():
            with self.subTest(brecha=key):
                self.assertEqual(UNKNOWN, classify(key, {}))
                corrupted = dict(data)
                corrupted[SPEC] = data[SPEC].replace(heading, "", 1)
                self.assertEqual(UNKNOWN, classify(key, corrupted))
                corrupted = dict(data)
                corrupted[path] = data[path].replace(marker, "", 1)
                self.assertNotEqual(data[path], corrupted[path])
                self.assertEqual(UNKNOWN, classify(key, corrupted))
                # Una prueba candidata ausente nunca acredita la fila.
                missing_test = dict(data)
                missing_test[GAPS[key][4]] = ""
                self.assertEqual(UNKNOWN, classify(key, missing_test))
        self.assertEqual(UNKNOWN, classify("D-049-S99", data))

    def test_sin_datos_reales(self) -> None:
        """AC-03: ningún acceso externo ni credencial/PII en la evidencia."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {"requests", "urllib", "httpx", "socket",
                                    "subprocess", "selenium", "playwright",
                                    "pymysql", "mysql"})
        self.assertEqual(set(local_sources()), set(SOURCES))
        fixture = {"tenant": "tenant-synthetic", "host": "tenant.example.invalid",
                   "actor": "synthetic-owner", "state": UNKNOWN}
        self.assertEqual(UNKNOWN, operational_state("D-049-S04", fixture))
        self.assertNotIn("email", fixture)
        self.assertNotIn("token", fixture)
        self.assertNotIn("password", fixture)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: los contratos offline no prueban navegación ni live."""
        data = local_sources()
        for key in GAPS:
            with self.subTest(brecha=key):
                self.assertEqual(DOCUMENTED, classify(key, data))
                for alleged in (None, {}, True, {"ci": "success"},
                                {"source": "synthetic", "test": "passed"},
                                {"state": "VALIDATED_IN_PRODUCTION"},
                                {"pr": "merged", "tenant": "synthetic"},
                                {"https": True, "dns": "verified"},
                                {"admin": True, "permission": "platform_owner"}):
                    self.assertEqual(UNKNOWN, operational_state(key, alleged))


if __name__ == "__main__":
    unittest.main()

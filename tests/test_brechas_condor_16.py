#!/usr/bin/env python3
"""Condor #1211: D-044-S08..D-045 gaps with synthetic, fail-closed evidence.

Documentary consistency never certifies operations, deployment or production.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-cd_d3.md"
SPEC = "ESPECIFICACIONES.md"
OBSERVER = "scripts/observar_release.py"
POST = "scripts/post-deploy.sh"
INDEXNOW = "src/Application/Seo/IndexNowNotifier.php"
SOURCES = (MATRIX, CONTRAST, SPEC, OBSERVER, POST, INDEXNOW)
TEST_CANDIDATES = {
    "D-044-S08": "tests/test_observar_release.py",
    "D-044-S09": "tests/test_post_deploy.py",
    "D-044-S10": "tests/test_observar_release.py",
    "D-044-S11": "tests/test_observar_release.py",
    "D-045": "tests/test_indexnow_notifier.py",
}
GAPS = {
    "D-044-S08": (
        "Automatización operativa", "#### Automatización operativa",
        ("smoke post-deploy", "verificación de release identity",
         "auditoría de dependencias", "mínimo privilegio"),
        OBSERVER, "DOMINIO_PRODUCCION",
    ),
    "D-044-S09": (
        "Reconciliación de esquema post-deploy en shared hosting",
        "#### Reconciliación de esquema post-deploy en shared hosting",
        ("CONDOR_PRODUCTION_STAGE", "CONDOR_AUTO_MIGRATE=0",
         "scripts/backup-database.sh", "fallan cerrado"),
        POST, "AUTO_MIGRATE",
    ),
    "D-044-S10": (
        "Revisión periódica de seguridad", "#### Revisión periódica de seguridad",
        ("seguridad se valida por slice", "vulnerability scanning en CI",
         "revisión trimestral", "no se silencian"),
        OBSERVER, "validar_health",
    ),
    "D-044-S11": (
        "Portabilidad operativa", "#### Portabilidad operativa",
        ("adapters/contratos", "Hostinger", "AWS", "necesidad real medible"),
        OBSERVER, "DOMINIO_PRODUCCION",
    ),
    "D-045": (
        "IndexNow y Google Tag Manager por tenant",
        "### D-045 — IndexNow y Google Tag Manager por tenant",
        ("IndexNow", "tenant", "GTM-[A-Z0-9]+", "CSP", "noindex"),
        INDEXNOW, "queuePublication",
    ),
}


def sources() -> dict[str, str]:
    """Only the checkout: no browser, network, database, or shell."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in SOURCES}


def rows(raw: str, kind: str) -> dict[str, tuple[str, ...]]:
    """Require all five distinct, complete, unaccredited source rows."""
    if kind not in ("matrix", "contrast"):
        raise ValueError("UNKNOWN: invalid source")
    out = {}
    for key, (title, *_rest) in GAPS.items():
        prefix = "| " + (chr(96) + key + chr(96) + " ·"
                        if kind == "matrix" else key + " |")
        matches = [line for line in raw.splitlines() if line.startswith(prefix)]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing or ambiguous " + key)
        cells = tuple(c.strip() for c in matches[0].strip("|").split("|"))
        if kind == "matrix":
            expected = chr(96) + key + chr(96) + " · " + chr(96) + SPEC + chr(96)
            if (len(cells) != 5 or cells[0] != expected or cells[1] != title
                    or cells[2] != chr(96) + "NO_VERIFICABLE" + chr(96)
                    or "Falta evidencia integral " + key not in cells[3]
                    or "tríada código/test/PR MERGED" not in cells[3]
                    or "contraste-cd_d3.md" not in cells[3]
                    or cells[4] != "#1"):
                raise ValueError("UNKNOWN: invalid matrix " + key)
        elif (len(cells) != 6 or cells[0] != key
              or cells[1] != "NO_VERIFICABLE" or cells[2] != GAPS[key][3]
              or cells[3] != TEST_CANDIDATES[key] or cells[4] != "—"
              or key not in cells[5]
              or "Falta acreditar integralmente" not in cells[5]
              or "tríada código/test/PR MERGED" not in cells[5]):
            raise ValueError("UNKNOWN: invalid contrast " + key)
        out[key] = cells
    return out


def clause(raw: str, heading: str) -> str:
    """Require exactly one normative section, without borrowing adjacent text."""
    lines = raw.splitlines()
    positions = [i for i, s in enumerate(lines) if s == heading]
    if len(positions) != 1:
        return ""
    level = len(heading) - len(heading.lstrip("#"))
    end = len(lines)
    for i in range(positions[0] + 1, len(lines)):
        m = re.match(r"^(#{1,6})\s+", lines[i])
        if m and len(m.group(1)) <= level:
            end = i
            break
    return "\n".join(lines[positions[0] + 1:end])


def classify(key: str, content: dict[str, str]) -> str:
    """Only attest that the partial candidate and remaining gap are recorded."""
    if key not in GAPS or not all(content.get(p) for p in SOURCES):
        return UNKNOWN
    try:
        matrix = rows(content[MATRIX], "matrix")
        contrast = rows(content[CONTRAST], "contrast")
    except ValueError:
        return UNKNOWN
    title, heading, phrases, path, marker = GAPS[key]
    if (matrix[key][1] != title or contrast[key][0] != key
            or marker not in content[path]
            or not all(p in clause(content[SPEC], heading) for p in phrases)):
        return UNKNOWN
    return GAP


def operational(_key: object, _claim: object = None) -> str:
    """A fixture, CI claim or partial source cannot prove a live state."""
    return UNKNOWN


class BrechasCondor16Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all five rows have executable assertions and explicit gap."""
        content = sources()
        self.assertEqual(set(rows(content[MATRIX], "matrix")), set(GAPS))
        self.assertEqual(set(rows(content[CONTRAST], "contrast")), set(GAPS))
        for key in GAPS:
            with self.subTest(brecha=key):
                self.assertEqual(GAP, classify(key, content))
                self.assertEqual(UNKNOWN, operational(key, content))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, forged or incomplete evidence is UNKNOWN."""
        content = sources()
        for path, kind in ((MATRIX, "matrix"), (CONTRAST, "contrast")):
            for key in GAPS:
                prefix = "| " + (chr(96) + key + chr(96) + " ·"
                                if kind == "matrix" else key + " |")
                row = next(line for line in content[path].splitlines()
                           if line.startswith(prefix))
                for candidate in (
                    content[path].replace(row, "", 1),
                    content[path] + "\n" + row,
                    content[path].replace(
                        row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
                    content[path].replace(row, prefix + " invalid |", 1),
                ):
                    with self.subTest(path=path, brecha=key):
                        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                            rows(candidate, kind)
        for key, (_title, heading, _phrases, path, marker) in GAPS.items():
            with self.subTest(brecha=key):
                self.assertEqual(UNKNOWN, classify(key, {}))
                changed = dict(content)
                changed[SPEC] = content[SPEC].replace(heading, "#### missing", 1)
                self.assertEqual(UNKNOWN, classify(key, changed))
                changed = dict(content)
                changed[path] = content[path].replace(marker, "", 1)
                self.assertEqual(UNKNOWN, classify(key, changed))

    def test_sin_datos_reales(self) -> None:
        """AC-03: no HTTP, subprocess, browser, DB, credentials or real users."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {a.name.split(".")[0] for n in ast.walk(tree)
                   if isinstance(n, ast.Import) for a in n.names}
        imports |= {n.module.split(".")[0] for n in ast.walk(tree)
                    if isinstance(n, ast.ImportFrom) and n.module}
        self.assertFalse(imports & {
            "requests", "urllib", "httpx", "socket", "subprocess",
            "selenium", "playwright", "pymysql", "mysql",
        })
        self.assertEqual(set(SOURCES), {
            MATRIX, CONTRAST, SPEC, OBSERVER, POST, INDEXNOW,
        })
        self.assertEqual(UNKNOWN, operational(
            "D-045", {"tenant": "synthetic", "gtm": "GTM-EXAMPLE"}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: offline verification cannot turn deployment into GREEN."""
        content = sources()
        for key in GAPS:
            with self.subTest(brecha=key):
                self.assertEqual(GAP, classify(key, content))
                for claim in (None, {}, True, {"ci": "success"},
                              {"security": "scan-pass"},
                              {"post_deploy": "ok", "schema": "synced"},
                              {"indexnow": "sent", "gtm": "enabled"},
                              {"state": "VALIDATED_IN_PRODUCTION"},
                              {"code": True, "test": True, "pr": "merged"}):
                    self.assertEqual(UNKNOWN, operational(key, claim))
        self.assertEqual(UNKNOWN, classify("D-045-S01", content))


if __name__ == "__main__":
    unittest.main()

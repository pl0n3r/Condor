#!/usr/bin/env python3
"""Condor #1239: cinco brechas documentadas con verificación fail-closed.

Un contrato versionado no demuestra autorización efectiva, despliegue,
resultado terminal de CI, versión de una política ni separación operativa.
Se usan únicamente fuentes del checkout, nunca producción o datos reales.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
SOURCE_ONLY = "FUENTE_LOCAL_NO_EJECUTADA"
TICK = chr(96)
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-cd_ops.md"
README = "README.md"
SPEC = "ESPECIFICACIONES.md"
FILES = (MATRIX, CONTRAST, README, SPEC)

# ID -> documento, subrequisito exacto, heading, evidencia documental parcial.
CLAUSES = {
    "README-10-05": (
        README,
        "Mapa de la fábrica — Condor / GrindFlow / BRVTAL: productos independientes.",
        "## Mapa de la fábrica",
        ("- **Condor / GrindFlow / BRVTAL:** productos independientes.",
         "- **Factory:** governance/kit y contratos compartidos."),
    ),
    "SPEC-5-5": (
        SPEC, "Regla de esta fase", "### 5.5 Regla de esta fase",
        ("Durante esta fase la prioridad es **definir el producto**.",
         "Una hipótesis de producto no debe convertirse automáticamente"),
    ),
    "SPEC-BASE-0": (
        SPEC, "Identidad", "## 1. Identidad",
        ("| Nombre oficial | **Condor App** |",
         "| Dominio canónico |", "| Repositorio | " + TICK + "pl0n3r/Condor" + TICK),
    ),
    "SPEC-BASE-1": (
        SPEC, "Regla de idioma", "## 2. Regla de idioma",
        ("Siempre que sea técnicamente viable", "español", "es-CO",
         "Se conservarán en inglés los elementos"),
    ),
    "SPEC-BASE-3": (
        SPEC, "Infraestructura objetivo inicial",
        "## 4. Infraestructura objetivo inicial",
        ("Hostinger shared hosting", "PHP 8.5", "Symfony 7.4 LTS",
         "MariaDB", "Node.js permitido", "despliegue desde " + TICK + "main" + TICK),
    ),
}


def local_sources() -> dict[str, str]:
    """Leer solo cuatro archivos versionados, sin HTTP ni credenciales."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def rows(raw: object, contrast: bool = False) -> dict[str, tuple[str, ...]]:
    """Rechazar filas omitidas, duplicadas incluso indentadas, o alteradas."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: fuente ilegible")
    result: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        stripped = line.lstrip()
        for key, (file, description, _, _) in CLAUSES.items():
            prefix = "| " + TICK + key + TICK
            prefix += " |" if contrast else " · " + TICK + file + TICK + " |"
            if not stripped.startswith(prefix):
                continue
            if key in result:
                raise ValueError("UNKNOWN: fila duplicada " + key)
            cells = tuple(x.strip() for x in stripped.strip("|").split("|"))
            if contrast:
                expected_evidence = (TICK + "tests/test_readme_contract_adoption.py" + TICK
                                     if key == "README-10-05" else TICK + "—" + TICK)
                good = (
                    len(cells) == 7
                    and cells[0] == TICK + key + TICK
                    and cells[1] == TICK + file + TICK
                    and cells[2] == TICK + "NO_VERIFICABLE" + TICK
                    and cells[3] == TICK + file + TICK
                    and cells[4] == expected_evidence
                    and cells[5] == "—"
                    and "Falta para " + key in cells[6]
                    and "NO_VERIFICABLE" in cells[6]
                )
            else:
                good = (
                    len(cells) == 5
                    and cells[0] == TICK + key + TICK + " · " + TICK + file + TICK
                    and cells[1] == description
                    and cells[2] == TICK + "NO_VERIFICABLE" + TICK
                    and ("Falta vincular para " + key in cells[3] if file == SPEC
                         else "Falta prueba independiente de la obligación" in cells[3])
                    and "contraste-cd_ops.md" in cells[3]
                    and cells[4] == "#1"
                )
            if not good:
                raise ValueError("UNKNOWN: evidencia inconsistente " + key)
            result[key] = cells
    if set(result) != set(CLAUSES):
        raise ValueError("UNKNOWN: faltan filas de cobertura")
    return result


def section(source: object, title: str) -> str | None:
    """El encabezado debe existir exactamente una vez y contener texto útil."""
    if type(source) is not str:
        return None
    lines = source.splitlines()
    if lines.count(title) != 1:
        return None
    start = lines.index(title) + 1
    depth = len(title) - len(title.lstrip("#"))
    end = next((i for i in range(start, len(lines))
                if lines[i].startswith("#")
                and 1 <= len(lines[i]) - len(lines[i].lstrip("#")) <= depth
                and lines[i].lstrip("#").startswith(" ")), len(lines))
    body = "\n".join(lines[start:end])
    return body if body.strip() else None


def inspect(key: object, sources: object, claimed_proof: object = None) -> dict[str, str]:
    """Evidencia documental parcial; jamás atribuir permisos, CI ni live."""
    result = {
        "gap": UNKNOWN, "source": UNKNOWN, "policy_version": UNKNOWN,
        "conflict_result": UNKNOWN, "permissions": UNKNOWN,
        "exact_head_ci": UNKNOWN, "deployment": UNKNOWN,
        "production": UNKNOWN,
    }
    if type(key) is not str or key not in CLAUSES or type(sources) is not dict:
        return result
    if set(sources) != set(FILES) or any(
        type(sources[path]) is not str or not sources[path] for path in FILES
    ):
        return result
    try:
        matrix = rows(sources[MATRIX])
        contrast = rows(sources[CONTRAST], contrast=True)
    except ValueError:
        return result
    if key not in matrix or key not in contrast:
        return result
    file, _, heading, markers = CLAUSES[key]
    block = section(sources[file], heading)
    if block is None or not all(marker in block for marker in markers):
        return result
    result["gap"] = GAP
    result["source"] = SOURCE_ONLY
    # Un recibo suministrado por un test tampoco demuestra identidad
    # autenticada del HEAD, CI terminal, infraestructura ni permisos.
    return result


class BrechasCondor44Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cinco casos vinculados a filas reales; sin falsa acreditación."""
        sources = local_sources()
        self.assertEqual(set(CLAUSES), set(rows(sources[MATRIX])))
        self.assertEqual(set(CLAUSES), set(rows(sources[CONTRAST], contrast=True)))
        for key in CLAUSES:
            with self.subTest(id=key):
                result = inspect(key, sources)
                self.assertEqual(GAP, result["gap"])
                self.assertEqual(SOURCE_ONLY, result["source"])
                self.assertEqual(UNKNOWN, result["production"])

    def test_falla_cerrado(self) -> None:
        """AC-02: ausencia, duplicado indentado, promoción y contrato alterado."""
        sources = local_sources()
        for file, is_contrast in ((MATRIX, False), (CONTRAST, True)):
            raw = sources[file]
            for key in CLAUSES:
                row = next(line for line in raw.splitlines()
                           if line.startswith("| " + TICK + key + TICK
                                              + (" |" if is_contrast else " ·")))
                variants = (
                    raw.replace(row, "", 1),
                    raw + "\n" + row,
                    raw + "\n   " + row,
                    raw.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                )
                for bad in variants:
                    with self.subTest(file=file, id=key):
                        with self.assertRaisesRegex(ValueError, UNKNOWN):
                            rows(bad, is_contrast)
                        self.assertEqual(UNKNOWN, inspect(
                            key, {**sources, file: bad})["gap"])
        for key, (file, _, heading, markers) in CLAUSES.items():
            with self.subTest(source=key):
                changed = sources[file].replace(markers[0], "EVIDENCIA_INEXISTENTE", 1)
                self.assertNotEqual(changed, sources[file])
                self.assertEqual(UNKNOWN, inspect(
                    key, {**sources, file: changed})["gap"])
                changed = sources[file].replace(heading, "## SIN CONTRATO", 1)
                self.assertEqual(UNKNOWN, inspect(
                    key, {**sources, file: changed})["gap"])
        for invalid in ("SPEC-BASE-999", None, 1239):
            self.assertEqual(UNKNOWN, inspect(invalid, sources)["gap"])
        self.assertEqual(UNKNOWN, inspect("SPEC-BASE-3", {})["gap"])
        self.assertIsNone(section("## prueba\n \n", "## prueba"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: lectura local sin acceso remoto ni secretos o PII."""
        sources = local_sources()
        self.assertEqual(set(FILES), set(sources))
        own = (ROOT / "tests/test_brechas_condor_44.py").read_text(encoding="utf-8")
        tree = ast.parse(own)
        names = {alias.name.split(".")[0] for node in ast.walk(tree)
                 if isinstance(node, ast.Import) for alias in node.names}
        names.update(node.module.split(".")[0] for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module)
        self.assertTrue(names.isdisjoint({
            "requests", "httpx", "urllib", "socket", "subprocess", "pymysql", "boto3",
        }))
        for file in FILES:
            self.assertTrue((ROOT / file).is_file())
            self.assertTrue((ROOT / file).resolve().is_relative_to(ROOT.resolve()))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI/prod/decisiones requieren recibos reales exact-HEAD."""
        sources = local_sources()
        for key in CLAUSES:
            with self.subTest(id=key):
                for forged in (None, {"status": "success"},
                               {"head_sha": "a" * 40, "conclusion": "success"},
                               {"approved": True, "production": "GREEN"}):
                    result = inspect(key, sources, forged)
                    self.assertEqual(GAP, result["gap"])
                    for dimension in (
                        "policy_version", "conflict_result", "permissions",
                        "exact_head_ci", "deployment", "production",
                    ):
                        self.assertEqual(UNKNOWN, result[dimension])
                self.assertEqual(UNKNOWN, inspect(key, {}, None)["gap"])


if __name__ == "__main__":
    unittest.main()

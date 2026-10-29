"""Condor #1219: GEST-FLUJO-1/P01..P04, solo contraste offline.

BRECHA_DOCUMENTADA no es implementado, aprobado, desplegado ni GREEN.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/gestion-empresarial-integral.md"
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
TITLES = {
    "GEST-FLUJO-1": "Perfil y cumplimiento",
    "GEST-FLUJO-1-P01": "Un administrador autorizado configura entidad legal, actividad/CIIU, municipio, sector, tamaño, régimen y fecha de referencia.",
    "GEST-FLUJO-1-P02": "El motor resuelve reglas versionadas como 'OBLIGATORIO', 'CONDICIONAL', 'PROHIBIDO', 'RECOMENDADO', 'NO_APLICA' o 'REQUIERE_REVISION', siempre con explicación, fuente, vigencia y condición.",
    "GEST-FLUJO-1-P03": "Crea obligaciones, responsables y fechas sin afirmar cumplimiento.",
    "GEST-FLUJO-1-P04": "Responsable adjunta evidencia o registra una acción; revisor con permiso distinto evalúa 'PENDIENTE', 'EN_REVISION', 'CUMPLE', 'INCUMPLE' o 'NO_APLICA'.",
}
FLOW = (
    "Un administrador autorizado configura entidad legal, actividad/CIIU, municipio, sector, tamaño, régimen y fecha de referencia.",
    "El motor resuelve reglas versionadas como `OBLIGATORIO`, `CONDICIONAL`, `PROHIBIDO`, `RECOMENDADO`, `NO_APLICA` o `REQUIERE_REVISION`, siempre con explicación, fuente, vigencia y condición.",
    "Crea obligaciones, responsables y fechas sin afirmar cumplimiento.",
    "Responsable adjunta evidencia o registra una acción; revisor con permiso distinto evalúa `PENDIENTE`, `EN_REVISION`, `CUMPLE`, `INCUMPLE` o `NO_APLICA`.",
)


def sources():
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in (MATRIX, SPEC)}


def rows(raw):
    if not isinstance(raw, str):
        raise ValueError(UNKNOWN)
    found = {}
    for key, title in TITLES.items():
        expected = f"| `{key}` · `{SPEC}` |"
        matches = [s for s in raw.splitlines() if s.startswith(expected)]
        if len(matches) != 1:
            raise ValueError(f"{UNKNOWN}: missing/duplicate {key}")
        fields = tuple(s.strip() for s in matches[0].strip("|").split("|"))
        if (len(fields) != 5 or fields[0] != f"`{key}` · `{SPEC}`"
                or fields[1] != title or fields[2] != "`NO_VERIFICABLE`"
                or "Falta vincular" not in fields[3]
                or "tríada código+prueba+PR fusionado" not in fields[3]
                or fields[4] != "#802"):
            raise ValueError(f"{UNKNOWN}: inconsistent {key}")
        found[key] = fields
    return found


def assess(key, docs):
    if key not in TITLES or not isinstance(docs, dict):
        return UNKNOWN
    if any(not isinstance(docs.get(p), str) or not docs[p] for p in (MATRIX, SPEC)):
        return UNKNOWN
    try:
        rows(docs[MATRIX])
    except ValueError:
        return UNKNOWN
    normative = docs[SPEC]
    heading = "### 1. Perfil y cumplimiento"
    if normative.count(heading) != 1 or "### 2. Documento controlado" not in normative:
        return UNKNOWN
    scope = normative.split(heading, 1)[1].split("### 2. Documento controlado", 1)[0]
    if any(scope.count(fragment) != 1 for fragment in FLOW):
        return UNKNOWN
    if "tenant → entidad legal → sede/municipio" not in normative:
        return UNKNOWN
    if "Un documento cargado no demuestra cumplimiento" not in normative:
        return UNKNOWN
    return GAP


def fixture_authority(context):
    """Modelo NEGATIVO de prueba, no el autorizador productivo de Symfony."""
    if not isinstance(context, dict):
        return UNKNOWN
    for p in ("tenant", "entity", "site", "actor_tenant", "actor_entity", "actor_site"):
        if not isinstance(context.get(p), str) or not context[p]:
            return UNKNOWN
    if any(context[a] != context[b] for a, b in (("tenant", "actor_tenant"),
              ("entity", "actor_entity"), ("site", "actor_site"))):
        return "DENY"
    if context.get("role") != "tenant_admin":
        return "DENY"
    return "SYNTHETIC_SCOPE_ONLY"


def fixture_review(data):
    """No inferir cumplimiento de archivo subido o check success."""
    if not isinstance(data, dict) or data.get("status") not in (
        "PENDIENTE", "EN_REVISION", "CUMPLE", "INCUMPLE", "NO_APLICA"
    ):
        return UNKNOWN
    if any(not isinstance(data.get(p), str) or not data[p] for p in ("actor", "reviewer")):
        return UNKNOWN
    if (data.get("actor") == data.get("reviewer")
            or data.get("reviewer_permitted") is not True
            or data.get("same_tenant") is not True
            or data.get("same_entity") is not True
            or data.get("same_site") is not True):
        return "DENY"
    return "SYNTHETIC_REVIEW_ONLY"


def production_state(_key, _allegation=None):
    return UNKNOWN


class BrechasCondor24Tests(unittest.TestCase):
    def test_contrato_principal(self):
        data = sources()
        self.assertEqual(set(TITLES), set(rows(data[MATRIX])))
        self.assertEqual(len(TITLES), 5)
        for key in TITLES:
            with self.subTest(key=key):
                self.assertEqual(GAP, assess(key, data))
                self.assertEqual(UNKNOWN, production_state(key, data))
        profile = {"tenant": "t1", "entity": "e1", "site": "s1",
                   "actor_tenant": "t1", "actor_entity": "e1", "actor_site": "s1",
                   "role": "tenant_admin"}
        self.assertEqual("SYNTHETIC_SCOPE_ONLY", fixture_authority(profile))
        self.assertEqual("DENY", fixture_authority({**profile, "actor_tenant": "t2"}))
        review = {"actor": "synthetic-a", "reviewer": "synthetic-b",
                  "reviewer_permitted": True, "same_tenant": True,
                  "same_entity": True, "same_site": True, "status": "EN_REVISION"}
        self.assertEqual("SYNTHETIC_REVIEW_ONLY", fixture_review(review))
        self.assertNotEqual("CUMPLE", fixture_review(review))

    def test_falla_cerrado(self):
        data = sources()
        for key in TITLES:
            line = next(s for s in data[MATRIX].splitlines()
                        if s.startswith(f"| `{key}` · `{SPEC}` |"))
            for bad in (data[MATRIX].replace(line, "", 1),
                        data[MATRIX] + "\n" + line,
                        data[MATRIX].replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(bad)
                    self.assertEqual(UNKNOWN, assess(key, {**data, MATRIX: bad}))
        self.assertEqual(UNKNOWN, assess("GEST-FLUJO-1", {}))
        self.assertEqual(UNKNOWN, assess("unknown", data))
        self.assertEqual(UNKNOWN, assess("GEST-FLUJO-1", {**data, SPEC: ""}))
        self.assertEqual(UNKNOWN, fixture_authority({}))
        self.assertEqual("DENY", fixture_review({"status": "CUMPLE",
                        "actor": "same", "reviewer": "same"}))
        self.assertEqual(UNKNOWN, fixture_review({"status": "CUMPLE", "reviewer": "synthetic-only"}))
        self.assertEqual(UNKNOWN, fixture_review({"status": "GO"}))

    def test_sin_datos_reales(self):
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {x.name.split(".")[0] for n in ast.walk(tree)
                   if isinstance(n, ast.Import) for x in n.names}
        imports |= {n.module.split(".")[0] for n in ast.walk(tree)
                    if isinstance(n, ast.ImportFrom) and n.module}
        self.assertFalse(imports & {"requests", "urllib", "socket", "subprocess",
                                    "httpx", "mysql", "pymysql", "playwright"})
        self.assertEqual({MATRIX, SPEC}, set(sources()))
        self.assertEqual(UNKNOWN, production_state("GEST-FLUJO-1", "synthetic"))

    def test_estados_unknown_explicitos(self):
        for key in TITLES:
            for fake in (None, {}, {"check": "success"}, {"pr": "merged"},
                         {"evidence": "uploaded"}, {"status": "CUMPLE"},
                         {"state": "VALIDATED_IN_PRODUCTION"},
                         {"source": "synthetic", "reviewed": True}):
                with self.subTest(key=key, claim=fake):
                    self.assertEqual(UNKNOWN, production_state(key, fake))
        self.assertNotIn("GREEN", (UNKNOWN, GAP))


if __name__ == "__main__":
    unittest.main()

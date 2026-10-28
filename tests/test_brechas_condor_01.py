#!/usr/bin/env python3
"""#1196: cinco contratos AGENTES, evidencia local sintética y fail-closed.

LOCAL_PARTIAL nunca acredita HEALTH productivo, E2E de es-CO/COP, versión
realmente desplegada ni autorización transversal en producción.
"""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"
TICK = chr(96)
MATRIX = ROOT / "docs/matriz-cobertura.md"
SOURCES = {
    "agents": ROOT / "AGENTES.md",
    "composer": ROOT / "composer.json",
    "health": ROOT / "src/Http/Controller/HealthController.php",
    "health_test": ROOT / "tests/php/Http/HealthContractControllerTest.php",
    "branch": ROOT / "src/Http/Controller/ApiContextController.php",
    "branch_test": ROOT / "tests/php/Http/BranchAccessControllerTest.php",
}
GAPS = {
    "AGENTES-02-06": (
        "2. Arranque específico de Condor — si producción deja de estar GREEN, "
        "priorizar su recuperación antes de trabajo dependiente."
    ),
    "AGENTES-03-04": "3. Contexto técnico — / Mercado / locale / Colombia / es-CO /",
    "AGENTES-03-05": "3. Contexto técnico — / Moneda por defecto / COP /",
    "AGENTES-03-06": "3. Contexto técnico — / Backend / PHP 8.5 + Symfony 7.4 LTS /",
    "AGENTES-04-01": (
        "4. Arquitectura y producto — servidor como autoridad final "
        "de validación y autorización;"
    ),
}


def coverage_rows(text: str) -> dict[str, tuple[str, ...]]:
    """Exactamente cinco brechas y sin promoción ficticia de cobertura."""
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\|\s*\x60(AGENTES-\d{2}-\d{2})\x60\s*·", line)
        if match is None or match.group(1) not in GAPS:
            continue
        key = match.group(1)
        columns = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if (key in found or len(columns) != 5 or not all(columns)
                or columns[0] != f"{TICK}{key}{TICK} · {TICK}AGENTES.md{TICK}"
                or columns[1] != GAPS[key]
                or columns[2] != f"{TICK}NO_VERIFICABLE{TICK}"
                or key not in columns[3]
                or "Falta evidencia integral" not in columns[3]):
            raise ValueError("UNKNOWN: fila duplicada/inválida " + key)
        found[key] = columns
    if set(found) != set(GAPS):
        raise ValueError("UNKNOWN: faltan brechas del contrato")
    return found


def recovery_priority(health: object, schema_current: object) -> str:
    """Prioridad de un ejemplo sintético, no lectura de HEALTH remoto."""
    if not isinstance(health, str):
        return UNKNOWN
    if health == "GREEN" and schema_current is True:
        return "CONTINUE_LOCAL"
    if health in {"DEGRADED", "RED"} or (health == "GREEN" and schema_current is False):
        return "RECOVER_FIRST"
    return UNKNOWN


def locale_contract(country: object, locale: object) -> str:
    if country == "Colombia" and locale == "es-CO":
        return LOCAL_PARTIAL
    return UNKNOWN


def default_currency(currency: object) -> str:
    return LOCAL_PARTIAL if currency == "COP" else UNKNOWN


def runtime_manifest(raw: object) -> str:
    """El lock/manifest es configuración, jamás evidencia del PHP del host."""
    if not isinstance(raw, str):
        return UNKNOWN
    try:
        doc = json.loads(raw)
    except (ValueError, TypeError):
        return UNKNOWN
    requires = doc.get("require") if isinstance(doc, dict) else None
    if not isinstance(requires, dict):
        return UNKNOWN
    required = ("symfony/framework-bundle", "symfony/security-bundle", "symfony/validator")
    if (requires.get("php") != "^8.5"
            or any(requires.get(package) != "7.4.*" for package in required)):
        return UNKNOWN
    return LOCAL_PARTIAL


def scoped_authorization(actor: object, tenant: object, branch: object,
                         permissions: object) -> str:
    """Decisión de muestra; el cliente nunca concedería privilegios."""
    if (not all(isinstance(v, str) and v.startswith("synthetic:")
                for v in (actor, tenant, branch))
            or not isinstance(permissions, dict)
            or set(permissions) != {"tenant", "branches", "active"}):
        return UNKNOWN
    branches = permissions["branches"]
    if (permissions["tenant"] != tenant or not isinstance(branches, list)
            or not branches or any(not isinstance(x, str) for x in branches)
            or branch not in branches or permissions["active"] is not True):
        return UNKNOWN
    return LOCAL_PARTIAL


def local_evidence(sources: dict[str, str]) -> dict[str, str]:
    """Señales reales de archivos + sintéticos; nunca promueve a CUBIERTO."""
    if (set(sources) != set(SOURCES)
            or any(not isinstance(x, str) or not x.strip() for x in sources.values())):
        return dict.fromkeys(GAPS, UNKNOWN)
    agents, composer = sources["agents"], sources["composer"]
    health, health_test = sources["health"], sources["health_test"]
    branch, branch_test = sources["branch"], sources["branch_test"]
    checks = {
        "AGENTES-02-06": (
            "si producción deja de estar GREEN" in agents
            and "priorizar su recuperación" in agents
            and "#[Route('/health'" in health
            and "getNewMigrations()" in health
            and "catch (Throwable)" in health
            and "testAnonymousHealthContractDoesNotLeakSecretsOrTenantContext" in health_test
            and recovery_priority("DEGRADED", True) == "RECOVER_FIRST"
        ),
        "AGENTES-03-04": (
            "Colombia / es-CO" in agents
            and locale_contract("Colombia", "es-CO") == LOCAL_PARTIAL
        ),
        "AGENTES-03-05": (
            "Moneda por defecto | COP" in agents
            and default_currency("COP") == LOCAL_PARTIAL
        ),
        "AGENTES-03-06": (
            "PHP 8.5 + Symfony 7.4 LTS" in agents
            and runtime_manifest(composer) == LOCAL_PARTIAL
        ),
        "AGENTES-04-01": (
            "servidor como autoridad final de validación y autorización" in agents
            and "AccessDeniedHttpException" in branch
            and "canAccessBranch(" in branch
            and "testCrossTenantIdsAreNotResolved" in branch_test
            and "testNonOwnerOnlySeesAssignedBranchesInContext" in branch_test
            and scoped_authorization(
                "synthetic:actor", "synthetic:tenant", "synthetic:branch",
                {"tenant": "synthetic:tenant", "branches": ["synthetic:branch"],
                 "active": True},
            ) == LOCAL_PARTIAL
        ),
    }
    return {key: LOCAL_PARTIAL if ok else UNKNOWN for key, ok in checks.items()}


def integral_coverage(clause: str, claim: object = None) -> str:
    """Ningún fixture/test del repositorio certifica ejecución productiva/E2E."""
    return UNKNOWN


class BrechasCondor01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = MATRIX.read_text(encoding="utf-8")
        cls.rows = coverage_rows(cls.document)
        cls.sources = {key: file.read_text(encoding="utf-8")
                       for key, file in SOURCES.items()}
        cls.local = local_evidence(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: cinco IDs con aserción de fuente o brecha explícita."""
        self.assertEqual(set(self.rows), set(GAPS))
        self.assertEqual(set(self.local), set(GAPS))
        for key, title in GAPS.items():
            with self.subTest(clause=key):
                self.assertEqual(self.rows[key][1], title)
                self.assertEqual(self.rows[key][2], f"{TICK}NO_VERIFICABLE{TICK}")
                self.assertEqual(self.local[key], LOCAL_PARTIAL)
                self.assertEqual(integral_coverage(key, self.local[key]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: sin contexto, datos inválidos, drift o cross-tenant, UNKNOWN."""
        first = next(x for x in self.document.splitlines()
                     if x.startswith("| \x60AGENTES-02-06\x60"))
        for corrupted in (self.document.replace(first, "", 1),
                          self.document + "\n" + first,
                          self.document.replace(first, first.replace(
                              "NO_VERIFICABLE", "CUBIERTO", 1))):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                coverage_rows(corrupted)
        for key, needle in (
            ("agents", "Colombia / es-CO"),
            ("composer", '"php": "^8.5"'),
            ("health", "getNewMigrations()"),
            ("branch", "canAccessBranch("),
            ("branch_test", "testCrossTenantIdsAreNotResolved"),
        ):
            with self.subTest(source=key):
                broken = dict(self.sources)
                self.assertIn(needle, broken[key])
                broken[key] = broken[key].replace(needle, "", 1)
                self.assertIn(UNKNOWN, local_evidence(broken).values())
        for value in (None, "", "offline", "UNKNOWN"):
            self.assertEqual(recovery_priority(value, True), UNKNOWN)
            self.assertEqual(default_currency(value), UNKNOWN)
            self.assertEqual(locale_contract("Colombia", value), UNKNOWN)
        self.assertEqual(recovery_priority("GREEN", None), UNKNOWN)
        self.assertEqual(runtime_manifest("{}"), UNKNOWN)
        self.assertEqual(runtime_manifest("not json"), UNKNOWN)
        self.assertEqual(scoped_authorization(
            "synthetic:actor", "synthetic:tenant-a", "synthetic:branch-b",
            {"tenant": "synthetic:tenant-b", "branches": ["synthetic:branch-b"],
             "active": True}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo contratos locales, sin llamadas de red ni datos sensibles."""
        self.assertTrue(all(file.is_file() for file in (MATRIX, *SOURCES.values())))
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", source)
        fake = {"actor": "synthetic:actor", "tenant": "synthetic:tenant"}
        self.assertTrue(all(value.startswith("synthetic:") for value in fake.values()))
        self.assertEqual(integral_coverage("AGENTES-04-01", fake), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: ningún CI auto-declarado atestigua host/E2E/producción."""
        for key in GAPS:
            with self.subTest(clause=key):
                self.assertEqual(self.rows[key][2], f"{TICK}NO_VERIFICABLE{TICK}")
                for fake in (None, "success", "CUBIERTO", LOCAL_PARTIAL,
                             {"ci": "success"}, {"host": "PHP 8.5"},
                             {"locale_e2e": "passed"}, {"live": True},
                             {"health": "GREEN"}):
                    self.assertEqual(integral_coverage(key, fake), UNKNOWN)
        self.assertEqual(integral_coverage("AGENTES-99-99"), UNKNOWN)
        self.assertEqual(local_evidence({}), dict.fromkeys(GAPS, UNKNOWN))


if __name__ == "__main__":
    unittest.main()

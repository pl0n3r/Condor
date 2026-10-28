#!/usr/bin/env python3
"""Condor #1210: brechas D-044-S03 a S07, con fixtures offline y fallo cerrado.

Comprueba contratos locales y deja las cinco filas como NO_VERIFICABLE:
una prueba sintética no acredita backups reales, métricas p75/p95,
canonical productivo, analítica de usuarios ni mantenimiento operativo.
"""
from __future__ import annotations

import json
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib import request

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAPS = {
    "D-044-S03": "Backups y recovery",
    "D-044-S04": "Performance y capacidad",
    "D-044-S05": "SEO multi-tenant",
    "D-044-S06": "Analítica de producto",
    "D-044-S07": "Mantenimiento y deuda técnica",
}


def source(relative_path: str) -> str:
    """Solo archivos versionados; no consulta servicios ni datos de clientes."""
    return (ROOT / relative_path).read_text(encoding="utf-8")


def matrix_rows(document: str) -> dict[str, list[str]]:
    """Una fila exacta por ID; omisiones, duplicados y celdas ambiguas fallan."""
    rows: dict[str, list[str]] = {}
    for line in document.splitlines():
        if not line.startswith("| `"):
            continue
        cells = [part.strip() for part in line.strip("|").split("|")]
        key = cells[0].split(" · ", 1)[0].strip(" `")
        if key not in GAPS:
            continue
        if key in rows or len(cells) != 5:
            raise ValueError(f"UNKNOWN: fila duplicada o malformada {key}")
        rows[key] = cells
    if set(rows) != set(GAPS):
        raise ValueError("UNKNOWN: faltan filas D-044-S03 a S07")
    return rows


def operational_evidence(identifier: str, claim: object = None) -> str:
    """Sin verificación externa autenticada, ninguna declaración es prueba live."""
    if identifier not in GAPS:
        raise ValueError(f"UNKNOWN: brecha fuera de alcance {identifier}")
    return UNKNOWN


class BrechasCondor15Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cinco controles concretos o brechas explícitas, sin omisiones."""
        rows = matrix_rows(source("docs/matriz-cobertura.md"))
        spec = source("ESPECIFICACIONES.md")
        for identifier, title in GAPS.items():
            with self.subTest(identifier=identifier):
                row = rows[identifier]
                self.assertEqual(row[1], title)
                self.assertEqual(row[2], "`NO_VERIFICABLE`")
                self.assertIn(f"Falta evidencia integral {identifier}", row[3])
                self.assertIn("#### " + title, spec)
                self.assertEqual(operational_evidence(identifier, row), UNKNOWN)

        # S03: antes del DROP de una DB descartable exige nombre y permiso.
        restore = source("scripts/verify-backup-restore.sh")
        self.assertIn("condor_backup_restored|condor_restore_*", restore)
        self.assertIn("CONDOR_ALLOW_DESTRUCTIVE_RESTORE", restore)
        self.assertLess(restore.index('case "$db" in'),
                        restore.index("DROP DATABASE IF EXISTS"))
        self.assertLess(restore.index("CONDOR_ALLOW_DESTRUCTIVE_RESTORE"),
                        restore.index("DROP DATABASE IF EXISTS"))
        self.assertIn("doctrine_migration_versions", restore)

        # S04: existe captación acotada, no prueba de Core Web Vitals real.
        metrics = source("src/Infrastructure/Observability/RequestMetrics.php")
        self.assertIn("private const MAX_ENTRIES = 500", metrics)
        self.assertIn("self::percentile($durations, 0.95)", metrics)
        self.assertIn("'error_rate' =>", metrics)
        self.assertIn("RequestMetrics::record(", source(
            "src/Infrastructure/Observability/RequestMetricsSubscriber.php"))

        # S05: el catálogo no cruza tenant ni host público; SEO total sigue UNKNOWN.
        public = source("src/Http/Controller/TenantPublicController.php")
        self.assertIn("TenantResolver::isPlatformHost($request->getHost())", public)
        self.assertIn("$current->id() !== $tenant->id()", public)
        seo = source("src/Http/Controller/SeoSettingsController.php")
        self.assertIn("authorizedBranchScope($branchId, 'settings.view')", seo)
        self.assertIn("authorizedBranchScope($branchId, 'settings.update')", seo)

        # S06: proyección comercial existente deniega ausencia de autoridad/audit.
        projection = source("src/Application/Commercial/SaasMetricsProjection.php")
        for marker in ("owner_required", "invalid_audit_evidence",
                       "'tenant_id'", "unavailable"):
            self.assertIn(marker, projection)

        # S07: mantenimiento automatizado existe, no sustituye auditoría real.
        dependabot = source(".github/dependabot.yml")
        for ecosystem in ("composer", "npm", "github-actions"):
            self.assertIn(f'package-ecosystem: "{ecosystem}"', dependabot)
        self.assertEqual(dependabot.count('interval: "monthly"'), 3)
        self.assertIn('version-update:semver-major', dependabot)

    def test_falla_cerrado(self) -> None:
        """AC-02: matriz incompleta/duplicada/ambigua nunca se valida."""
        document = source("docs/matriz-cobertura.md")
        self.assertEqual(set(matrix_rows(document)), set(GAPS))
        for identifier in GAPS:
            row = next(line for line in document.splitlines()
                       if line.startswith(f"| `{identifier}` ·"))
            for invalid in (document.replace(row + "\n", "", 1),
                            document + "\n" + row,
                            document.replace(row, f"| `{identifier}` · rota |", 1)):
                with self.subTest(identifier=identifier), self.assertRaisesRegex(
                    ValueError, "UNKNOWN"
                ):
                    matrix_rows(invalid)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            operational_evidence("D-044-S99", {"ci": "success"})

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures mínimos, solo lecturas locales, cero HTTP y secretos."""
        with patch.object(request, "urlopen", side_effect=AssertionError("sin red")):
            rows = matrix_rows(source("docs/matriz-cobertura.md"))
            for identifier in GAPS:
                self.assertEqual(operational_evidence(identifier, {
                    "source": "fixture", "tenant": "synthetic-tenant"
                }), UNKNOWN)
        fixture = {"tenant": "synthetic-tenant", "sha": "a" * 40,
                   "state": UNKNOWN, "environment": "offline"}
        rendered = json.dumps(fixture, sort_keys=True).lower()
        for forbidden in ("password", "access_token", "authorization",
                          "database_url", "cookie", "email", "nit"):
            self.assertNotIn(forbidden, rendered)
        self.assertEqual(set(rows), set(GAPS))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: ni pruebas verdes ni claims sintéticos acreditan D-044."""
        rows = matrix_rows(source("docs/matriz-cobertura.md"))
        for identifier in GAPS:
            with self.subTest(identifier=identifier):
                self.assertEqual(rows[identifier][2], "`NO_VERIFICABLE`")
                for claim in (None, {}, True, {"ci": "success"},
                              {"offline": True, "tests": "passed"},
                              {"state": "VALIDATED_IN_PRODUCTION",
                               "sha": "a" * 40, "synthetic": True},
                              {"backup": "claimed", "recovery": "claimed"},
                              {"p95_ms": 100, "tenant": "synthetic-tenant"}):
                    self.assertEqual(operational_evidence(identifier, claim), UNKNOWN)


if __name__ == "__main__":
    unittest.main()

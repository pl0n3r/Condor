#!/usr/bin/env python3
"""#1983: 41 filas GEST, testigos reales parciales y negativos fail-closed.

El contraste NO certifica los 41 flujos integrales ni implica producción.
Ejecuta PHP puro con registros inventados, sin red, datos reales ni DB.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTRAST = ROOT / "docs/contraste-gestion-limites-f0-f6.md"
REPORT = ROOT / "docs/verificacion/gest-limits-norms-phases.md"
SOURCE = ROOT / "docs/gestion-empresarial-integral.md"
BASE = runpy.run_path(str(ROOT / "tests/test_verificacion_gest_limits_norms_phases.py"))
EXPECTED = frozenset(BASE["IDS"])
RECEIPTS = {
    "norm": ("1816", "3faf719694e3",
             "tests/e2e/test_gest_norm_phases.py",
             "GestNormPhasesE2ETests", "test_normative_http_mariadb_and_versioned_rules"),
    "scope": ("1723", "474d900e7e0d",
              "tests/e2e/test_gest_scope_negative_sql.py",
              "GestScopeNegativeSqlTests", "test_mariadb_scope_and_rollback"),
    "flow": ("1795", "1e3973d8d4cf",
             "tests/e2e/test_gest_flows_integrados.py",
             "GestFlowsIntegradosTests", "test_authenticated_http_mariadb_transaction"),
}
ROW = re.compile(
    r"^\| \x60(?P<id>GEST-[A-Z0-9-]+)\x60"
    r" \| \x60(?P<status>[^|]+)\x60"
    r" \| (?P<test>[^|]+)"
    r" \| (?P<pr>[^|]+)"
    r" \| (?P<reason>[^|]+) \|$"
)


def group_for(identifier: str) -> str | None:
    if identifier == "GEST-F4":
        return None
    if (identifier.startswith("GEST-NORM-")
            or re.fullmatch(r"GEST-F[0-6]", identifier)
            or identifier in {"GEST-AC-01", "GEST-AC-04", "GEST-AC-05", "GEST-AC-06"}):
        return "norm"
    if identifier.startswith("GEST-LIM-") or identifier == "GEST-AC-03":
        return "scope"
    return "flow"


def parse_contrast(raw: str) -> dict[str, dict[str, str]]:
    """Fail closed on missing, duplicate, promoted or unverifiable evidence."""
    if raw.count("## Concordancia exhaustiva por ID") != 1:
        raise ValueError("UNKNOWN: contraste sin sección única")
    section = raw.split("## Concordancia exhaustiva por ID", 1)[1].split(
        "## Guardas de seguridad", 1,
    )[0]
    found: dict[str, dict[str, str]] = {}
    for line in section.splitlines():
        if not line.startswith("| ") or "GEST-" not in line:
            continue
        match = ROW.fullmatch(line)
        if match is None:
            raise ValueError("UNKNOWN: fila malformada")
        row = match.groupdict()
        identifier = row["id"]
        if identifier not in EXPECTED or identifier in found:
            raise ValueError("UNKNOWN: ID extra o repetido")
        if row["status"] != "NO_VERIFICABLE":
            raise ValueError("UNKNOWN: promoción sin acreditación integral")
        if len(row["reason"].strip()) < 25:
            raise ValueError("UNKNOWN: falta motivo reproducible")
        kind = group_for(identifier)
        if kind is None:
            if row["test"].strip() != "**SIN_TESTIGO_DIRECTO**" or row["pr"].strip() != "—":
                raise ValueError("UNKNOWN: testigo o PR inventado")
        else:
            number, sha, test_file, cls, method = RECEIPTS[kind]
            proof = f"[\x60{test_file}::{cls}::{method}\x60](../{test_file})"
            pr = f"[#{number}](https://github.com/pl0n3r/Condor/pull/{number}) (\x60{sha}\x60)"
            if row["test"].strip() != proof or row["pr"].strip() != pr:
                raise ValueError("UNKNOWN: testigo parcial o PR no autorizado")
        found[identifier] = row
    if set(found) != EXPECTED or len(found) != 41:
        raise ValueError("UNKNOWN: 41 filas incompletas")
    return found


class GestLimitesF0F6Tests(unittest.TestCase):
    def test_01_41_rows_match_primary_audit_without_promotion(self) -> None:
        actual = parse_contrast(CONTRAST.read_text(encoding="utf-8"))
        original = BASE["report_rows"](REPORT.read_text(encoding="utf-8"))
        source = BASE["source_anchors"](SOURCE.read_text(encoding="utf-8"))
        self.assertEqual(EXPECTED, set(original))
        self.assertEqual(EXPECTED, set(source))
        for identifier, row in actual.items():
            with self.subTest(identifier=identifier):
                parts = original[identifier].split("|")
                self.assertEqual("NO_VERIFICABLE", parts[2].strip())
                self.assertEqual(parts[4].strip(), row["reason"].strip())
                self.assertNotEqual("CUBIERTO", row["status"])
        self.assertEqual(1, sum(group_for(x) is None for x in actual))

    def test_02_partial_witnesses_are_existing_executable_methods(self) -> None:
        rows = parse_contrast(CONTRAST.read_text(encoding="utf-8"))
        for identifier in rows:
            kind = group_for(identifier)
            if kind is None:
                continue
            with self.subTest(identifier=identifier):
                number, sha, path, cls, method = RECEIPTS[kind]
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertRegex(source, r"\bclass\s+" + re.escape(cls) + r"\s*\(")
                self.assertEqual(
                    1, len(re.findall(r"(?m)^\s+def\s+" + re.escape(method) + r"\s*\(", source)),
                )
                header = CONTRAST.read_text(encoding="utf-8")
                self.assertIn(f"[PR #{number}]", header)
                self.assertIn(sha, header)
        self.assertIn("SIN_TESTIGO_DIRECTO", rows["GEST-F4"]["test"])
        self.assertIn("#742", CONTRAST.read_text(encoding="utf-8"))
        self.assertIn("D-059/D-063", CONTRAST.read_text(encoding="utf-8"))

    def test_03_missing_duplicated_promoted_and_forged_rows_fail_closed(self) -> None:
        doc = CONTRAST.read_text(encoding="utf-8")
        target = next(line for line in doc.splitlines() if line.startswith("| \x60GEST-NORM-01\x60 |"))
        cases = (
            doc.replace(target, "", 1),
            doc.replace(target, target + "\n" + target, 1),
            doc.replace(target, target.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            doc.replace(target, target.replace("3faf719694e3", "123456789abc"), 1),
            doc.replace(target, target.replace(
                "Demostrar gobierno de fuentes, fronteras, responsables y trazabilidad en un flujo autorizado.", "sin dato",
            ), 1),
        )
        for index, changed in enumerate(cases):
            with self.subTest(mutation=index), self.assertRaises(ValueError):
                parse_contrast(changed)

    def test_04_tenant_legal_entity_site_and_owner_veto_are_denied_synthetically(self) -> None:
        allow = BASE["permit_synthetic"]
        owner = {"tenant": "tenant-a", "entity": "entity-a", "site": "site-a"}
        self.assertEqual("LECTURA_SINTETICA", allow(owner, dict(owner)))
        for key in owner:
            for bad in ("foreign-" + key, "", None):
                with self.subTest(dimension=key, invalid=bad):
                    record = dict(owner)
                    record[key] = bad
                    self.assertEqual("DENEGADO", allow(owner, record))
        self.assertEqual("DENEGADO", allow(owner, {"tenant": "tenant-a"}))
        self.assertNotEqual("CUMPLE", BASE["preview_evidence"]("PENDIENTE", True, True))
        for decision, d059, d063 in (
            (None, True, True), ("B", True, True),
            ("OWNER_APROBADO", False, True), ("OWNER_APROBADO", True, False),
        ):
            self.assertEqual("DENEGADO", BASE["labor_gate"](decision, d059, d063))

        # The actual HTTP/MariaDB negative regression is owned by merged PR #1723.
        php_regression = (ROOT / "tests/php/Http/GestScopeNegativeSqlTest.php").read_text(encoding="utf-8")
        self.assertIn("function testNegativeTenantLegalEntityBranchRoleAndSqlRollback(", php_regression)
        self.assertIn("SENSITIVE_NOT_EXPORTABLE", php_regression)
        self.assertIn("medical_notes", php_regression)

    def test_05_real_php_csv_projection_never_exports_foreign_or_medical_fixture(self) -> None:
        """Execute a real production class; this is NOT a common-document E2E."""
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI obligatorio; sin SKIP ficticio")
        service = ROOT / "src/Application/Reporting/TenantCsvReportService.php"
        self.assertTrue(service.is_file())
        records = [
            {"tenant_id": "tenant-a", "occurred_at": "2026-11-05",
             "order_id": "OWN-GEST-1983", "currency": "COP", "total_amount_minor": 1000,
             "payment_status": "PENDING", "medical_notes": "PRIVATE_SYNTHETIC_MEDICAL_1983",
             "clinical_history": "FICTIONAL_CLINICAL_ONLY"},
            {"tenant_id": "tenant-b", "occurred_at": "2026-11-05",
             "order_id": "FOREIGN-GEST-1983", "currency": "COP",
             "total_amount_minor": 2000, "payment_status": "PENDING"},
        ]
        script = r"""
require $argv[1];
$records = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$service = new \App\Application\Reporting\TenantCsvReportService();
echo json_encode($service->build('sales', 'tenant-a', '2026-11-01', '2026-11-06', $records), JSON_THROW_ON_ERROR);
"""
        process = subprocess.run(
            [php, "-n", "-d", "allow_url_fopen=0", "-r", script, str(service), json.dumps(records)],
            cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(0, process.returncode, process.stdout + process.stderr)
        output = json.loads(process.stdout)
        self.assertIn("OWN-GEST-1983", output["body"])
        for forbidden in ("FOREIGN-GEST-1983", "PRIVATE_SYNTHETIC_MEDICAL_1983",
                          "FICTIONAL_CLINICAL_ONLY", "medical_notes", "clinical_history",
                          "tenant-b"):
            self.assertNotIn(forbidden, output["body"])

    def test_06_real_php_normativity_stays_reviewable_not_certified(self) -> None:
        suite = runpy.run_path(str(ROOT / "tests/test_compliance_normative_applicability.py"))
        profile, rule, evaluate = (suite[name] for name in ("profile", "rule", "evaluate"))
        standard = rule()
        valid = evaluate(standard, profile())
        self.assertEqual("OBLIGATORIO", valid["kind"])
        self.assertEqual(standard["source"], valid["source"])
        for overridden in (
            {"approved": False},
            {"reviewer_ref": ""},
            {"source": {}},
            {"verified_on": ""},
        ):
            with self.subTest(invalid=overridden):
                self.assertEqual(
                    "REQUIERE_REVISION",
                    evaluate({**standard, **overridden}, profile())["kind"],
                )
        self.assertEqual("NO_APLICA", evaluate(standard, profile(ciiu="6201"))["kind"])
        self.assertEqual("REQUIERE_REVISION", evaluate(standard, profile(municipio="unknown"))["kind"])

    def test_07_stays_offline_and_ci_selects_python_regression(self) -> None:
        from scripts.ci_change_classifier import classify
        changed = ["docs/contraste-gestion-limites-f0-f6.md", "tests/test_gestion_limites_f0_f6.py"]
        selection = classify(changed, "pull_request")
        self.assertTrue(selection.pruebas_base)
        source = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                self.assertFalse({a.name.split(".")[0] for a in node.names}
                                 & {"requests", "urllib", "socket", "httpx"})
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0],
                                 {"requests", "urllib", "socket", "httpx"})


if __name__ == "__main__":
    unittest.main()

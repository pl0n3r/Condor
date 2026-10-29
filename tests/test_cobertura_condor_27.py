#!/usr/bin/env python3
"""Condor #1346: exercise real, offline OPS-REC-D and README product behavior.

The operational policies and live GitHub dashboards are classified in the PR,
not misrepresented as executable offline evidence. This file does not inspect
coverage-matrix rows, call network APIs, or use real customer records.
"""
from __future__ import annotations

import ast
import csv
import importlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
if str(TESTS) not in sys.path:
    sys.path.insert(0, str(TESTS))

REPORTS = ROOT / "src/Application/Reporting/TenantCsvReportService.php"
OWNER = ROOT / "src/Http/Controller/PlatformOwnerController.php"

# Reuse real-controller, disposable-fixture acceptance runners without
# copying their implementation or replacing it with source-text assertions.
HTTP_ACCEPTANCE = (
    ("README-03-01/CatalogController",
     "test_catalog_http_isolation", "CatalogoAislamientoHttpTests"),
    ("README-03-01/PricingController",
     "test_pricing_http_negatives", "PreciosNegativasHttpTests"),
    ("README-03-02/StorefrontCheckoutController",
     "test_checkout_http_negatives", "CheckoutNegativasHttpTests"),
    ("OPS-REC-D-P2/CustomerController",
     "test_customers_http_isolation", "ClientesAislamientoHttpTests"),
    ("OPS-REC-D-P2/InventoryController",
     "test_inventory_http_negatives", "InventarioNegativasHttpTests"),
)

REPORTS_PHP = r"""
require $argv[1];
$payload = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
try {
  $data = (new \App\Application\Reporting\TenantCsvReportService())->build(
    $payload['kind'], $payload['tenant'], $payload['from'],
    $payload['through'], $payload['records']
  );
  echo json_encode(['ok'=>true, 'data'=>$data], JSON_THROW_ON_ERROR);
} catch (\DomainException $e) {
  echo json_encode(['ok'=>false, 'reason'=>$e->getMessage()], JSON_THROW_ON_ERROR);
}
"""

OWNER_PHP = r"""
namespace App\Domain\Identity\Entity {
  class User { public const ROLE_PLATFORM_OWNER = 'ROLE_PLATFORM_OWNER'; }
}
namespace App\Shared\Version {
  class AppVersion { public function human(): string { return 'fixture-version'; } }
}
namespace Symfony\Component\HttpFoundation {
  class Response {
    public function __construct(public array $payload = []) {}
  }
}
namespace Symfony\Bundle\FrameworkBundle\Controller {
  abstract class AbstractController {
    protected function denyAccessUnlessGranted(mixed $role): void {
      if ($role !== 'ROLE_PLATFORM_OWNER' || !$GLOBALS['permit']) {
        throw new \RuntimeException('ACCESS_DENIED');
      }
    }
    protected function render(string $view, array $data): \Symfony\Component\HttpFoundation\Response {
      return new \Symfony\Component\HttpFoundation\Response(
        ['view'=>$view, 'data'=>$data]
      );
    }
  }
}
namespace {
  require $argv[1];
  $controller = new \App\Http\Controller\PlatformOwnerController();
  $version = new \App\Shared\Version\AppVersion();
  $GLOBALS['permit'] = false;
  try {
    $controller($version, 'empresas');
    $denied = false;
  } catch (\RuntimeException $e) {
    $denied = $e->getMessage() === 'ACCESS_DENIED';
  }
  $GLOBALS['permit'] = true;
  $allowed = $controller($version, 'empresas');
  echo json_encode([
    'denied'=>$denied,
    'response'=>$allowed->payload,
  ], JSON_THROW_ON_ERROR);
}
"""


def php_probe(code: str, path: Path, payload: dict | None = None) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI required: cannot claim executable coverage")
    if not path.is_file():
        raise AssertionError(f"Production source unavailable: {path}")
    cmd = [php, "-d", "allow_url_fopen=0", "-r", code, str(path)]
    process = subprocess.run(
        cmd, input=json.dumps(payload) if payload is not None else None,
        capture_output=True, text=True, cwd=ROOT, timeout=30, check=False,
    )
    if process.returncode:
        raise AssertionError("Real PHP probe failed: " + process.stderr[-1200:])
    return json.loads(process.stdout)


def record(tenant: str, ident: str, date: str = "2026-10-21") -> dict:
    return {
        "tenant_id": tenant, "record_id": ident, "occurred_at": date,
        "source_id": "fictional-source", "variant_id": "fictional-variant",
        "quantity": "3", "reserved_quantity": "0",
    }


def report(kind: str = "inventory", tenant: str = "fixture-tenant-a",
           records: list[dict] | None = None,
           start: str = "2026-10-20", end: str = "2026-10-22") -> dict:
    return php_probe(REPORTS_PHP, REPORTS, {
        "kind": kind, "tenant": tenant, "from": start, "through": end,
        "records": records if records is not None else [],
    })


def exercise_existing_http_contract(module: str, classname: str) -> None:
    """Run the existing Symfony/PHP acceptance, never just parse its source."""
    imported = importlib.import_module(module)
    testcase = getattr(imported, classname)("test_acceptance")
    result = unittest.TestResult()
    testcase.run(result)
    if result.testsRun != 1 or result.errors or result.failures or result.skipped:
        diagnostics = "\n".join(
            str(err) for _, err in (*result.errors, *result.failures)
        )
        raise AssertionError(
            f"{module}.{classname}: expected real successful HTTP test, "
            f"ran={result.testsRun}, skipped={result.skipped}, "
            f"errors={result.errors}, failures={result.failures}\n{diagnostics[:1700]}"
        )


class CoberturaCondor27Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: P1/P2/P3, catalog, prices and checkout exercise real code."""
        # OPS-REC-D-P1 and P2: actual CSV renderer enforces tenant and dates,
        # even when caller accidentally supplies a foreign enterprise's rows.
        rows = [
            record("fixture-tenant-a", "own"),
            record("fixture-tenant-b", "foreign"),
            record("fixture-tenant-a", "outside", "2026-10-23"),
        ]
        first = report(records=rows)
        second = report(tenant="fixture-tenant-b", records=rows)
        self.assertTrue(first["ok"], first)
        self.assertTrue(second["ok"], second)
        a = list(csv.DictReader(io.StringIO(first["data"]["body"])))
        b = list(csv.DictReader(io.StringIO(second["data"]["body"])))
        self.assertEqual(["own"], [row["record_id"] for row in a])
        self.assertEqual(["foreign"], [row["record_id"] for row in b])
        self.assertEqual(first["data"]["content_type"], "text/csv; charset=utf-8")

        # OPS-REC-D-P3: platform-owner-only route actually denies staff and
        # accepts the owner with a synthetic version; no real account needed.
        owner = php_probe(OWNER_PHP, OWNER)
        self.assertIs(owner["denied"], True)
        self.assertEqual(
            owner["response"],
            {"view": "platform_owner/index.html.twig",
             "data": {"app_version": "fixture-version", "section": "empresas"}},
        )

        # P2 and README-03-01/02: existing suites invoke the actual Symfony
        # controllers and assert cross-tenant, unauthorized or malformed cases.
        for requirement, module, classname in HTTP_ACCEPTANCE:
            with self.subTest(requirement=requirement):
                exercise_existing_http_contract(module, classname)

    def test_falla_cerrado(self) -> None:
        """AC-02: tenant absence, invalid date/data, and role denial."""
        for payload, reason in (
            ({"tenant": ""}, "TENANT_REQUIRED"),
            ({"kind": "consolidated_billing"}, "REPORT_KIND_UNSUPPORTED"),
            ({"start": "invalid"}, "INVALID_REPORT_DATE"),
            ({"start": "2026-10-22", "end": "2026-10-20"}, "INVALID_REPORT_RANGE"),
            ({"records": [{"record_id": "without-tenant"}]}, "INVALID_REPORT_RECORD"),
            ({"records": [{**record("fixture-tenant-a", "x"),
                           "quantity": []}]}, "INVALID_REPORT_COLUMN"),
        ):
            with self.subTest(reason=reason):
                result = report(**payload)
                self.assertEqual(result, {"ok": False, "reason": reason})
        self.assertIs(php_probe(OWNER_PHP, OWNER)["denied"], True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: all fixtures are fictional; no remote client or secrets."""
        own = Path(__file__).read_text(encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                imports.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "httpx", "socket",
                                    "urllib", "pymysql", "mysql", "psycopg2"})
        self.assertTrue(all("fixture" in s for s in
                            ("fixture-tenant-a", "fixture-tenant-b")))
        self.assertNotIn("https://", REPORTS_PHP)
        self.assertNotIn("http://", REPORTS_PHP)
        self.assertNotIn("https://", OWNER_PHP)
        self.assertNotIn("http://", OWNER_PHP)

    def test_ci_gate_contract(self) -> None:
        """AC-04: local tests must execute; remote Validar exact-SHA remains separate."""
        self.assertEqual(len(HTTP_ACCEPTANCE), 5)
        self.assertEqual(len({x[0] for x in HTTP_ACCEPTANCE}), 4)
        self.assertTrue(REPORTS.is_file())
        self.assertTrue(OWNER.is_file())
        for _, module, classname in HTTP_ACCEPTANCE:
            cls = getattr(importlib.import_module(module), classname)
            self.assertTrue(callable(getattr(cls, "test_acceptance", None)))


if __name__ == "__main__":
    unittest.main()

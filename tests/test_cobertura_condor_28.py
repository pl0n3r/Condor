#!/usr/bin/env python3
"""Condor #1347: executable offline portions of README-03-03..README-07-01.

Exercise application PHP classes with disposable synthetic identities.
An isolated domain test does not certify integrated HTTP, live deployment,
visual UI, an actual database, or production release readiness.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

ROWS = frozenset((
    "README-03-03", "README-03-04", "README-03-05", "README-03-06",
    "README-04-01", "README-05-01", "README-05-02", "README-05-03",
    "README-05-04", "README-05-05", "README-06-01", "README-07-01",
))
A = frozenset((
    "README-03-03", "README-03-04", "README-03-05",
    "README-03-06", "README-04-01", "README-06-01",
))
B = {
    "README-05-01": "Symfony/Doctrine/MySQL integration needs a real isolated DB and HTTP kernel",
    "README-05-02": "React/TypeScript/Vite behavior needs a built admin and interacting browser",
    "README-05-03": "Twig SEO SSR needs a real HTTP render and browser/accessibility evidence",
    "README-05-04": "Hostinger deployment and observer require actual external release receipts",
    "README-05-05": "CI pipeline outcome belongs to GitHub exact-SHA checks, not a local fixture",
}
C = {
    "README-07-01": "Factory governance and local AGENTES are binding policy, not a business function",
}
D: dict[str, str] = {}

# Runs actual PHP domain/application code; never mocks the business functions.
# The four controllers have broader HTTP/DB obligations not asserted here.
PHP_CASE = r"""
declare(strict_types=1);
require $argv[1] . '/vendor/autoload.php';
use App\Domain\Orders\OrderStateMachine;
use App\Application\Orders\OrderStateTransitions;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Identity\Entity\User;
use App\Application\Identity\BranchAuthorization;
use App\Application\Inventory\StockCsvAdjuster;
use App\Application\Purchasing\QuotationPreview;

$deny = static function (callable $fn): string {
    try { $fn(); } catch (\Throwable $e) { return get_class($e); }
    return 'UNEXPECTED_ACCEPT';
};
$tenantId = 'tenant-fixture-alpha';
$at = new DateTimeImmutable('2026-10-29T00:00:00+00:00');
$machine = new OrderStateMachine();
$orders = new OrderStateTransitions($machine);
$order = $machine->initial($tenantId, 10000);
$paid = $orders->recordPayment($order, 4000, true, $tenantId, $tenantId, 'actor-fixture', $at);
$cancel = $orders->change($order, 'order', 'cancelled', $tenantId, $tenantId, 'actor-fixture', $at);

$tenant = new Tenant('Empresa ficticia A', 'fixture-alpha');
$foreign = new Tenant('Empresa ficticia B', 'fixture-beta');
$category = new CommercialCategory($tenant, 'Minorista', 'minorista');
$foreignCategory = new CommercialCategory($foreign, 'Ajena', 'ajena');
$customer = new Customer($tenant, '  Comprador de prueba  ', 'DEMO@EXAMPLE.INVALID');
$customer->assignCommercialCategory($category);
$branch = new Branch($foreign, 'Sucursal ajena', 'ajena');
$user = new User('operator@example.invalid', 'Operador de prueba');
// The cross-tenant branch denial occurs before ORM access; no DB is opened.
$authorization = (new ReflectionClass(BranchAuthorization::class))->newInstanceWithoutConstructor();

$branchId = str_repeat('A', 26);
$csv = "sku,branch_id,quantity\nsku-1,$branchId,7\nsku-1,$branchId,7\nmal formado,$branchId,-1\n";
$parsed = StockCsvAdjuster::parseCsv($csv);
$key = StockCsvAdjuster::rowKey($tenantId, $parsed['sha256'], 2);

$quote = [
    'tenant_ref' => 'fixture:tenant/alpha',
    'legal_entity_ref' => 'fixture:entity/alpha',
    'site_ref' => 'fixture:site/alpha',
    'currency' => 'COP',
    'mode' => 'preview_only',
    'lines' => [[
        'item_ref' => 'fixture:item/material',
        'tenant_ref' => 'fixture:tenant/alpha',
        'legal_entity_ref' => 'fixture:entity/alpha',
        'site_ref' => 'fixture:site/alpha',
        'quantity' => '2.000',
        'unit_price_cop' => '100.00',
    ]],
];
$preview = QuotationPreview::preview($quote);
$foreignQuote = $quote;
$foreignQuote['lines'][0]['tenant_ref'] = 'fixture:tenant/foreign';
$badQuote = $quote;
$badQuote['lines'][0]['quantity'] = '-1';
$version = require $argv[1] . '/config/version.php';
echo json_encode([
    'orders' => [
        'initial' => $order['payment'],
        'balance' => $orders->pendingBalance($paid),
        'paid_minor' => $paid['paid_minor'],
        'history' => count($paid['history']),
        'cancelled' => $cancel['order'],
        'released' => $cancel['fulfillment'],
        'foreign_denial' => $deny(static fn() => $orders->recordPayment(
            $order, 100, true, $tenantId, 'tenant-fixture-foreign', 'actor-fixture', $at
        )),
        'bad_amount' => $deny(static fn() => $orders->recordPayment(
            $order, -1, true, $tenantId, $tenantId, 'actor-fixture', $at
        )),
    ],
    'customers' => [
        'normalized_name' => $customer->name(),
        'normalized_email' => $customer->email(),
        'tenant_ok' => $customer->commercialCategory()?->tenant()->id() === $tenant->id(),
        'foreign_denial' => $deny(static fn() => $customer->assignCommercialCategory($foreignCategory)),
        'invalid_email' => $deny(static fn() => new Customer($tenant, 'Ejemplo', 'no-es-email')),
    ],
    'inventory' => [
        'rows' => $parsed['rows'],
        'errors' => $parsed['errors'],
        'key' => $key,
        'key_deterministic' => $key === StockCsvAdjuster::rowKey($tenantId, $parsed['sha256'], 2),
        'foreign_key_denial' => $deny(static fn() => StockCsvAdjuster::rowKey('', $parsed['sha256'], 2)),
        'bad_header' => $deny(static fn() => StockCsvAdjuster::parseCsv('invalid,header')),
    ],
    'purchasing' => [
        'preview' => $preview,
        'foreign_denial' => $deny(static fn() => QuotationPreview::preview($foreignQuote)),
        'bad_quantity' => $deny(static fn() => QuotationPreview::preview($badQuote)),
    ],
    'architecture' => [
        'cross_tenant_branch_denial' => $deny(static fn() => $authorization->permissions($user, $tenant, $branch)),
    ],
    'version' => $version,
], JSON_THROW_ON_ERROR);
"""


@lru_cache(maxsize=1)
def php_case() -> dict:
    php = shutil.which("php")
    if php is None or not (ROOT / "vendor/autoload.php").is_file():
        raise AssertionError("UNKNOWN: PHP and installed Composer autoload required")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_CASE, str(ROOT)],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=60,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: real application PHP failed: " + result.stderr[-1200:])
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: invalid PHP execution evidence") from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: PHP probe did not return an object")
    return data


class CoberturaCondor28Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(ROWS), 12)
        self.assertEqual(A | B.keys() | C.keys() | D.keys(), ROWS)
        self.assertEqual(len(A), 6)
        result = php_case()
        self.assertEqual(
            (result["orders"]["initial"], result["orders"]["balance"],
             result["orders"]["paid_minor"], result["orders"]["history"],
             result["orders"]["cancelled"], result["orders"]["released"]),
            ("pending", 6000, 4000, 1, "cancelled", "released"),
        )
        customer = result["customers"]
        self.assertEqual(customer["normalized_name"], "Comprador de prueba")
        self.assertEqual(customer["normalized_email"], "demo@example.invalid")
        self.assertTrue(customer["tenant_ok"])
        inv = result["inventory"]
        self.assertEqual(len(inv["rows"]), 1)
        self.assertEqual(inv["rows"][0]["sku"], "SKU-1")
        self.assertEqual(inv["rows"][0]["quantity"], 7)
        self.assertEqual([row["error"] for row in inv["errors"]],
                         ["CSV_DUPLICATE_SKU_BRANCH", "CSV_ROW_INVALID"])
        self.assertTrue(inv["key_deterministic"])
        self.assertRegex(inv["key"], r"^csv-count-[0-9a-f]{48}-2$")
        purchasing = result["purchasing"]["preview"]
        self.assertEqual(
            (purchasing["status"], purchasing["total_cop"], purchasing["line_count"]),
            ("SYNTHETIC_PREVIEW", 200, 1),
        )
        for effect in ("can_order", "can_pay", "can_approve", "can_dispatch", "persisted"):
            self.assertIs(purchasing[effect], False, effect)
        version = result["version"]
        self.assertIsInstance(version, dict)
        self.assertRegex(version["version"], r"^\d+\.\d+\.\d+$")

    def test_falla_cerrado(self) -> None:
        p = php_case()
        self.assertEqual(p["orders"]["foreign_denial"], "DomainException")
        self.assertEqual(p["orders"]["bad_amount"], "DomainException")
        self.assertEqual(p["customers"]["foreign_denial"], "DomainException")
        self.assertEqual(p["customers"]["invalid_email"], "DomainException")
        self.assertEqual(p["inventory"]["foreign_key_denial"], "DomainException")
        self.assertEqual(p["inventory"]["bad_header"], "DomainException")
        self.assertEqual(p["purchasing"]["foreign_denial"], "InvalidArgumentException")
        self.assertEqual(p["purchasing"]["bad_quantity"], "InvalidArgumentException")
        self.assertEqual(
            p["architecture"]["cross_tenant_branch_denial"],
            "Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException",
        )

    def test_sin_datos_reales(self) -> None:
        self.assertEqual((len(A), len(B), len(C), len(D)), (6, 5, 1, 0))
        for group in (B, C, D):
            self.assertTrue(all(key in ROWS and reason for key, reason in group.items()))
        source = Path(__file__).read_text(encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"socket", "requests", "httpx", "urllib", "pymysql"})
        self.assertNotRegex(PHP_CASE, r"(?i)curl_exec|mysqli_connect|PDO\(|https?://")
        self.assertIn("example.invalid", PHP_CASE)
        self.assertTrue(all("/" not in row for row in A))
        self.assertEqual(php_case()["orders"]["balance"], 6000)


if __name__ == "__main__":
    unittest.main()

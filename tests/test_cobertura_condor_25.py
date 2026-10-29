#!/usr/bin/env python3
"""#1344 — offline operational journey regressions against real Condor PHP.

Exercises production classes/controllers with synthetic data. It never reads the
coverage matrix, touches a database/network, or authorizes live operations.
Pending capability statements are classified in the PR/issue, not simulated.
"""
from __future__ import annotations

import csv
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
if str(TESTS) not in sys.path:
    sys.path.insert(0, str(TESTS))
import test_cobertura_condor_21 as purchasing
import test_checkout_http_negatives as checkout

STOCK = ROOT / "src/Application/Inventory/StockCsvAdjuster.php"
REPORTS = ROOT / "src/Application/Reporting/TenantCsvReportService.php"
CATEGORY_FILES = [
    ROOT / "src/Domain/Commerce/Entity" / name
    for name in ("CommercialRecord.php", "CommercialItem.php",
                 "NamedCommercialItem.php", "CommercialCategory.php", "Customer.php")
]
PRODUCTION = ROOT / "src/Domain/Production/Entity/ProductionOrder.php"
BRANCH = "01ARZ3NDEKTSV4RRFFQ69G5FAV"

STOCK_PHP = r"""
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
try {
    $parsed = \App\Application\Inventory\StockCsvAdjuster::parseCsv($input['csv']);
    $keys = array_map(
        static fn($row) => \App\Application\Inventory\StockCsvAdjuster::rowKey(
            $input['tenant'], $parsed['sha256'], $row['line']
        ), $parsed['rows']
    );
    echo json_encode(['ok' => true, 'parsed' => $parsed, 'keys' => $keys], JSON_THROW_ON_ERROR);
} catch (\DomainException $error) {
    echo json_encode(['ok' => false, 'reason' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
"""

REPORTS_PHP = r"""
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
try {
    $data = (new \App\Application\Reporting\TenantCsvReportService())->build(
        $input['kind'], $input['tenant'], $input['from'], $input['through'], $input['rows']
    );
    echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR);
} catch (\DomainException $error) {
    echo json_encode(['ok' => false, 'reason' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
"""

CUSTOMERS_PHP = r"""
namespace App\Shared\Id {
    class UlidFactory {
        private static int $next = 0;
        public static function new(): string {
            return str_pad((string) ++self::$next, 26, '0', STR_PAD_LEFT);
        }
    }
}
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $identifier) {}
        public function id(): string { return $this->identifier; }
    }
}
namespace {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Commerce\Entity\CommercialCategory;
    use App\Domain\Commerce\Entity\Customer;
    foreach (array_slice($argv, 1) as $path) { require $path; }
    function denied(callable $action): bool {
        try { $action(); return false; }
        catch (\DomainException $error) { return true; }
    }
    $a = new Tenant('fixture-tenant-a');
    $b = new Tenant('fixture-tenant-b');
    $category = new CommercialCategory($a, 'Minoristas', 'minoristas');
    $foreign = new CommercialCategory($b, 'Ajenos', 'ajenos');
    $customer = new Customer($a, 'Cliente ficticio', null, null, null, $category);
    $denied = [
        denied(fn() => new CommercialCategory($a, '', 'valid')),
        denied(fn() => new CommercialCategory($a, 'Bien', '../unsafe')),
        denied(fn() => new Customer($a, '', null)),
        denied(fn() => new Customer($a, 'Ficticio', 'invalid-email')),
        denied(fn() => $customer->assignCommercialCategory($foreign)),
    ];
    echo json_encode([
        'category' => $customer->commercialCategory()?->slug(),
        'customer' => $customer->name(), 'tenant' => $customer->tenant()->id(),
        'denied' => $denied, 'still_same_category' =>
        $customer->commercialCategory() === $category,
    ], JSON_THROW_ON_ERROR);
}
"""

PRODUCTION_PHP = r"""
namespace App\Shared\Id {
    class UlidFactory {
        private static int $next = 0;
        public static function new(): string {
            return str_pad((string) ++self::$next, 26, '0', STR_PAD_LEFT);
        }
    }
}
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $identifier) {}
        public function id(): string { return $this->identifier; }
    }
}
namespace App\Domain\Catalog\Entity {
    class ProductVariant {
        public function __construct(
            private \App\Domain\Organization\Entity\Tenant $scope,
            private string $reference
        ) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant {
            return $this->scope;
        }
        public function id(): string { return $this->reference; }
    }
}
namespace App\Domain\Inventory\Entity {
    class InventorySource {
        public function __construct(
            private \App\Domain\Organization\Entity\Tenant $scope,
            private string $reference
        ) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant {
            return $this->scope;
        }
        public function id(): string { return $this->reference; }
    }
}
namespace App\Domain\Production\Entity {
    class BillOfMaterials {
        public function __construct(
            private \App\Domain\Organization\Entity\Tenant $scope,
            private \App\Domain\Catalog\Entity\ProductVariant $variant
        ) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant {
            return $this->scope;
        }
        public function variant(): \App\Domain\Catalog\Entity\ProductVariant {
            return $this->variant;
        }
        public function id(): string { return 'fixture-bom'; }
        public function version(): int { return 1; }
    }
}
namespace {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Inventory\Entity\InventorySource;
    use App\Domain\Production\Entity\BillOfMaterials;
    use App\Domain\Production\Entity\ProductionOrder;
    require $argv[1];
    function denied(callable $action): bool {
        try { $action(); return false; }
        catch (\DomainException $error) { return true; }
    }
    $a = new Tenant('tenant-a');
    $b = new Tenant('tenant-b');
    $variant = new ProductVariant($a, 'fixture-variant');
    $bom = new BillOfMaterials($a, $variant);
    $source = new InventorySource($a, 'fixture-site');
    $order = new ProductionOrder($a, $bom, $variant, $source, 3);
    $denied = [
        denied(fn() => new ProductionOrder($a, $bom, $variant,
                                          new InventorySource($b, 'foreign-site'), 3)),
        denied(fn() => new ProductionOrder($a, $bom, $variant, $source, 0)),
        denied(fn() => $order->complete(2, 'fixture-completion')),
        denied(fn() => $order->saveCostSnapshot([])),
    ];
    $before = $order->status();
    $order->complete(3, 'fixture-completion');
    $order->complete(3, 'fixture-completion');
    $denied[] = denied(fn() => $order->complete(3, 'changed-key'));
    $denied[] = denied(fn() => $order->complete(4, 'fixture-completion'));
    $denied[] = denied(fn() => $order->saveCostSnapshot([
        'state' => 'ESTIMATED_COST', 'tenant_id' => $b->id()
    ]));
    echo json_encode([
        'before' => $before, 'after' => $order->status(),
        'completed' => $order->completedQuantity(),
        'completion_key' => $order->completionIdempotencyKey(),
        'denied' => $denied, 'snapshot' => $order->costSnapshot(),
    ], JSON_THROW_ON_ERROR);
}
"""


def php_run(script: str, paths: list[Path], payload: dict | None = None) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI missing: no real-code coverage")
    if any(not item.is_file() for item in paths):
        raise AssertionError("required production source unavailable")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", script, *map(str, paths)],
        input=json.dumps(payload) if payload is not None else None,
        text=True, capture_output=True, cwd=ROOT, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP fixture failure: " + result.stderr[-1250:])
    return json.loads(result.stdout)


def physical_count(*, qty: int = 12) -> str:
    return f"sku,branch_id,quantity\nSKU-TEST,{BRANCH},{qty}\n"


def quotation() -> dict:
    return {
        "tenant_ref": "fixture:tenant/alpha",
        "legal_entity_ref": "fixture:company/main",
        "site_ref": "fixture:site/main", "currency": "COP",
        "mode": "preview_only",
        "lines": [{
            "item_ref": "fixture:item/bolt",
            "tenant_ref": "fixture:tenant/alpha",
            "legal_entity_ref": "fixture:company/main",
            "site_ref": "fixture:site/main",
            "quantity": "2", "unit_price_cop": "1230.50",
        }],
    }


def report_record(tenant: str, ident: str, date: str) -> dict:
    return {
        "tenant_id": tenant, "record_id": ident,
        "occurred_at": date, "source_id": "fixture-site",
        "variant_id": "SKU-TEST", "quantity": "12",
        "reserved_quantity": "0",
    }


class CoberturaCondor25Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: real offline contracts for 7 operational rows; no doc asserts."""
        # OPS-M04-PRUEBA + OPS-REC-A-P2: branch SKU and idempotent count.
        count = php_run(STOCK_PHP, [STOCK], {
            "csv": physical_count(), "tenant": "tenant-a",
        })
        self.assertTrue(count["ok"])
        self.assertEqual([("SKU-TEST", BRANCH, 12)], [
            (row["sku"], row["branch_id"], row["quantity"])
            for row in count["parsed"]["rows"]
        ])
        self.assertEqual(count["keys"], php_run(STOCK_PHP, [STOCK], {
            "csv": physical_count(), "tenant": "tenant-a",
        })["keys"])
        self.assertNotEqual(count["keys"], php_run(STOCK_PHP, [STOCK], {
            "csv": physical_count(), "tenant": "tenant-b",
        })["keys"])

        # OPS-M05-PRUEBA: commercial customer categories and mandatory fields.
        clients = php_run(CUSTOMERS_PHP, CATEGORY_FILES)
        self.assertEqual(("minoristas", "Cliente ficticio", "fixture-tenant-a"),
                         (clients["category"], clients["customer"], clients["tenant"]))
        self.assertTrue(clients["still_same_category"])
        self.assertEqual([True] * 5, clients["denied"])

        # OPS-M06-PRUEBA: real Supplier/PurchaseOrder domain, receipt idempotency.
        domain = purchasing.php_run(
            purchasing.DOMAIN_PHP,
            ROOT / "src/Domain/Purchasing/Entity/Supplier.php",
            str(ROOT / "src/Domain/Purchasing/Entity/PurchaseOrderLine.php"),
            str(ROOT / "src/Domain/Purchasing/Entity/PurchaseOrder.php"),
        )
        self.assertEqual(("draft", "received", True),
                         (domain["before"], domain["after"], domain["received"]))
        self.assertEqual(1, domain["line_count"])
        self.assertEqual([True] * 8, domain["denials"])

        # OPS-M07-PRUEBA: real ProductionOrder validates BOM/site, completion.
        produced = php_run(PRODUCTION_PHP, [PRODUCTION])
        self.assertEqual(("draft", "completed", 3),
                         (produced["before"], produced["after"], produced["completed"]))
        self.assertEqual("fixture-completion", produced["completion_key"])
        self.assertIsNone(produced["snapshot"])
        self.assertEqual([True] * 7, produced["denied"])

        # OPS-M08-PRUEBA: production CSV renderer isolates tenant/date.
        records = [
            report_record("tenant-a", "included", "2026-10-20"),
            report_record("tenant-b", "foreign", "2026-10-20"),
            report_record("tenant-a", "out-of-range", "2026-10-22"),
        ]
        csv_report = php_run(REPORTS_PHP, [REPORTS], {
            "kind": "inventory", "tenant": "tenant-a",
            "from": "2026-10-20", "through": "2026-10-21",
            "rows": records,
        })
        self.assertTrue(csv_report["ok"])
        parsed = list(csv.reader(io.StringIO(csv_report["data"]["body"])))
        self.assertEqual(parsed[0], [
            "record_id", "occurred_at", "source_id", "variant_id",
            "quantity", "reserved_quantity",
        ])
        self.assertEqual([row[0] for row in parsed[1:]], ["included"])
        self.assertNotIn("foreign", csv_report["data"]["body"])

        # OPS-REC-A-P1: offline price and catalog scope, COP and preview only.
        quote = purchasing.php_run(
            purchasing.QUOTE_PHP,
            ROOT / "src/Application/Purchasing/QuotationPreview.php",
            data=quotation(),
        )
        self.assertEqual(("SYNTHETIC_PREVIEW", "COP", 2461),
                         (quote["status"], quote["currency"], quote["total_cop"]))
        self.assertFalse(quote["persisted"])
        self.assertFalse(quote["can_pay"])

        # OPS-REC-A-P2: actual public checkout controller and its support trait.
        with tempfile.TemporaryDirectory() as temporary:
            fixture_file = Path(temporary) / "checkout.php"
            fixture_file.write_text(
                checkout.FIXTURE.replace("__ITEMS__", json.dumps(str(checkout.ITEM_SUPPORT)))
                .replace("__CONTROLLER__", json.dumps(str(checkout.CONTROLLER))),
                encoding="utf-8",
            )
            checked = subprocess.run(
                [shutil.which("php") or "php", str(fixture_file)],
                text=True, capture_output=True, timeout=40, check=False, cwd=ROOT,
            )
        self.assertEqual(0, checked.returncode, checked.stderr[-1400:])
        response = json.loads(checked.stdout)
        self.assertEqual(201, response["valid_status"])
        self.assertEqual(5600, response["tenant_a_server_total"])
        self.assertEqual(404, response["foreign_variant"])
        self.assertEqual(422, response["client_total"])

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed site, identity, currency, range and state deny."""
        invalid = php_run(STOCK_PHP, [STOCK], {
            "csv": "sku,branch_id,quantity\n", "tenant": "tenant-a",
        })
        self.assertFalse(invalid["ok"])
        invalid_branch = php_run(STOCK_PHP, [STOCK], {
            "csv": "sku,branch_id,quantity\nSKU-TEST,wrong,5\n",
            "tenant": "tenant-a",
        })
        self.assertTrue(invalid_branch["ok"])
        self.assertEqual([], invalid_branch["parsed"]["rows"])
        self.assertEqual("CSV_ROW_INVALID", invalid_branch["parsed"]["errors"][0]["error"])
        duplicates = php_run(STOCK_PHP, [STOCK], {
            "csv": physical_count() + f"SKU-TEST,{BRANCH},15\n",
            "tenant": "tenant-a",
        })
        self.assertEqual("CSV_DUPLICATE_SKU_BRANCH",
                         duplicates["parsed"]["errors"][0]["error"])
        for change in (
            {"currency": "USD"}, {"mode": "create_order"},
            {"lines": [{**quotation()["lines"][0],
                        "tenant_ref": "fixture:tenant/beta"}]},
            {"lines": [{**quotation()["lines"][0], "quantity": "0"}]},
        ):
            bad = purchasing.php_run(
                purchasing.QUOTE_PHP,
                ROOT / "src/Application/Purchasing/QuotationPreview.php",
                data={**quotation(), **change},
            )
            with self.subTest(quote=change):
                self.assertIn("error", bad)
                self.assertNotIn("total_cop", bad)
        for report in (
            {"kind": "inventory", "tenant": "", "from": "2026-10-01",
             "through": "2026-10-02", "rows": []},
            {"kind": "inventory", "tenant": "tenant-a",
             "from": "2026-10-03", "through": "2026-10-02", "rows": []},
            {"kind": "inventory", "tenant": "tenant-a",
             "from": "2026-02-30", "through": "2026-10-02", "rows": []},
            {"kind": "inventory", "tenant": "tenant-a",
             "from": "2026-10-01", "through": "2026-10-02",
             "rows": [{"tenant_id": "tenant-a", "occurred_at": "2026-10-01"}]},
        ):
            with self.subTest(report=report):
                denied = php_run(REPORTS_PHP, [REPORTS], report)
                self.assertFalse(denied["ok"])
                self.assertIn(denied["reason"], {
                    "TENANT_REQUIRED", "INVALID_REPORT_RANGE",
                    "INVALID_REPORT_DATE", "INVALID_REPORT_COLUMN",
                })
        self.assertEqual([True] * 5, php_run(CUSTOMERS_PHP, CATEGORY_FILES)["denied"])
        self.assertEqual([True] * 7, php_run(PRODUCTION_PHP, [PRODUCTION])["denied"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: fictitious data only, no network, providers or live operations."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        self.assertTrue(all(row["item_ref"].startswith("fixture:")
                            for row in quotation()["lines"]))
        self.assertIn(BRANCH, physical_count())
        self.assertEqual("tenant-a", report_record("tenant-a", "fixture-r", "2026-10-20")["tenant_id"])
        with mock.patch("socket.create_connection",
                        side_effect=AssertionError("network forbidden")):
            response = php_run(STOCK_PHP, [STOCK], {
                "csv": physical_count(), "tenant": "tenant-a",
            })
            self.assertTrue(response["ok"])
        preview = purchasing.php_run(
            purchasing.QUOTE_PHP,
            ROOT / "src/Application/Purchasing/QuotationPreview.php",
            data=quotation(),
        )
        self.assertFalse(preview["can_order"])
        self.assertFalse(preview["can_pay"])
        self.assertFalse(preview["persisted"])


if __name__ == "__main__":
    unittest.main()

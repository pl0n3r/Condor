#!/usr/bin/env python3
"""#1340: exercise real PHP boundaries for twelve enterprise M08/M09/M10 rows.

Only synthetic, offline subcontracts are proved. Neither procurement
compliance nor document exports, real suppliers, approvals or live readiness
are certified. The matrix is deliberately not modified by this test.
"""
from __future__ import annotations

import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
if str(TESTS) not in sys.path:
    sys.path.insert(0, str(TESTS))

import test_m10_activos as assets
import test_assets_http_api as asset_api

MATRIX = ROOT / "docs/matriz-cobertura.md"
A = {
    "GEST-M08-S08": "DocumentApprovalRetention: synthetic event history and reviewer roles, no authentic external audit",
    "GEST-M09-S01": "Supplier: real canonical code and tenant-safe domain behavior, not a complete procurement workflow",
    "GEST-M09-S02": "QuotationPreview: exact COP integer totals and tenant-scope guards, preview only",
    "GEST-M09-S04": "PurchaseOrder: real tenant guards and state transitions, with no supplier negotiation workflow",
    "GEST-M09-S05": "PurchaseOrder: real idempotent receipt/stock boundaries, not a physical goods receipt",
    "GEST-M10-S03": "AssetRegisterLifecycle: real responsible reference and synthetic assignment, not an HR identity",
    "GEST-M10-S07": "AssetApiService: real evidence-gated transition and audit, no authentic attachment storage",
}
D = {
    "GEST-M08-S07": "No authorized versioned export generator, download or redacted document package is implemented",
    "GEST-M09-S03": "Procurement requisition submission and approval workflow is not implemented",
    "GEST-M09-S06": "Versioned supplier evaluation and objective scoring workflow is not implemented",
    "GEST-M09-S07": "Supplier contract lifecycle, sign-off and immutable contract evidence are not implemented",
    "GEST-M09-S08": "Accounts-payable ledger, reconciliation and payable authorization are not implemented",
}
IDS = frozenset(A) | frozenset(D)
PROJECT_PHP = r"""
require $argv[1];
$cls = $argv[2];
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
echo json_encode($cls::project($input), JSON_THROW_ON_ERROR);
"""
QUOTE_PHP = r"""
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
try {
    $output = \App\Application\Purchasing\QuotationPreview::preview($input);
} catch (\InvalidArgumentException $e) {
    $output = ['error' => $e->getMessage()];
}
echo json_encode($output, JSON_THROW_ON_ERROR);
"""
SUPPLIER_PHP = r"""
require $argv[1];
try {
    $out = ['code' => \App\Domain\Purchasing\Entity\Supplier::canonicalCode($argv[2])];
} catch (\DomainException $e) {
    $out = ['error' => 'denied'];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def rows_from(raw: str) -> dict[str, tuple[str, ...]]:
    """Reject missing, duplicated or promoted rows, not just an absent heading."""
    if not isinstance(raw, str) or not raw:
        raise ValueError("UNKNOWN: empty matrix")
    rows = {}
    for line in raw.splitlines():
        match = re.match(
            r"^\| `(GEST-M(?:08|09|10)-S\d{2})` · "
            r"`docs/gestion-empresarial-integral\.md` \|", line,
        )
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        cols = tuple(piece.strip() for piece in line.strip("|").split("|"))
        if (key in rows or len(cols) != 5
                or cols[2] != "`NO_VERIFICABLE`"
                or cols[4] != "#802" or not all(cols)):
            raise ValueError("UNKNOWN: malformed or promoted " + key)
        rows[key] = cols
    if set(rows) != IDS or len(rows) != 12:
        raise ValueError("UNKNOWN: incomplete twelve-row scope")
    return rows


def php_run(script: str, file: Path, *args: str, data: dict | None = None) -> dict:
    php = shutil.which("php")
    if php is None or not file.is_file():
        raise AssertionError("real PHP source and CLI required")
    command = [php, "-d", "allow_url_fopen=0", "-r", script, str(file), *args]
    result = subprocess.run(
        command, cwd=ROOT,
        input=json.dumps(data) if data is not None else None,
        capture_output=True, text=True, timeout=25, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP real-code fixture failed: " + result.stderr[-1200:])
    return json.loads(result.stdout)


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


def documentary() -> dict:
    return {
        "tenant_ref": "tenant-a", "company_ref": "fixture:company/main",
        "as_of": "2026-10-29",
        "records": [{
            "tenant_ref": "tenant-a", "company_ref": "fixture:company/main",
            "ref": "fixture:dossier/demo", "classification": "RESERVADA",
            "author_ref": "fixture:actor/editor",
            "events": [
                {"action": "REVISAR", "actor_ref": "fixture:actor/reviewer",
                 "role": "revisor", "at": "2026-10-27"},
                {"action": "APROBAR", "actor_ref": "fixture:actor/approver",
                 "role": "aprobador", "at": "2026-10-28"},
            ],
        }],
    }


DOMAIN_PHP = r"""
namespace Doctrine\Common\Collections {
    interface Collection {}
    class ArrayCollection implements Collection {
        private array $items = [];
        public function add(mixed $item): void { $this->items[] = $item; }
        public function toArray(): array { return $this->items; }
        public function isEmpty(): bool { return $this->items === []; }
    }
}
namespace App\Shared\Id {
    class UlidFactory {
        private static int $n = 0;
        public static function new(): string {
            return str_pad((string) ++self::$n, 26, "0", STR_PAD_LEFT);
        }
    }
}
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $ref) {}
        public function id(): string { return $this->ref; }
    }
    class LegalEntity {
        public function __construct(private Tenant $tenant) {}
        public function tenant(): Tenant { return $this->tenant; }
    }
}
namespace App\Domain\Production\Entity {
    class Material {
        public function __construct(private \App\Domain\Organization\Entity\Tenant $owner,
                                    private string $ref) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant { return $this->owner; }
        public function id(): string { return $this->ref; }
        public function isActive(): bool { return true; }
    }
    class MaterialInventoryBalance {
        public static function normalizeQuantity(string $quantity): string {
            if (preg_match('/^[1-9][0-9]*(?:\.[0-9]{1,6})?$/D', $quantity) !== 1) {
                throw new \DomainException('Invalid synthetic quantity');
            }
            return $quantity;
        }
    }
}
namespace {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\LegalEntity;
    use App\Domain\Production\Entity\Material;
    use App\Domain\Purchasing\Entity\Supplier;
    use App\Domain\Purchasing\Entity\PurchaseOrder;
    require $argv[1];
    require $argv[2];
    require $argv[3];
    function denied(callable $action): bool {
        try { $action(); return false; }
        catch (\DomainException $error) { return true; }
    }
    $alpha = new Tenant('tenant-a');
    $beta = new Tenant('tenant-b');
    $companyA = new LegalEntity($alpha);
    $companyB = new LegalEntity($beta);
    $supplier = new Supplier($alpha, 'sup-1', 'Proveedor sintético', 30);
    $foreign = new Supplier($beta, 'sup-2', 'Proveedor sintético', 30);
    $denials = [
        denied(fn() => new PurchaseOrder($alpha, $companyB, $supplier)),
        denied(fn() => new PurchaseOrder($alpha, $companyA, $foreign)),
    ];
    $order = new PurchaseOrder($alpha, $companyA, $supplier);
    $material = new Material($alpha, 'material-a');
    $denials[] = denied(fn() => $order->addLine(new Material($beta, 'foreign'), '1', '10'));
    $order->addLine($material, '2', '10.25');
    $denials[] = denied(fn() => $order->addLine($material, '1', '10'));
    $before = $order->status();
    $source = str_repeat('a', 26);
    $denials[] = denied(fn() => $order->markReceived('fixture-receipt', 'short'));
    $order->markReceived('fixture-receipt', $source);
    $order->markReceived('fixture-receipt', $source);
    $denials[] = denied(fn() => $order->markReceived('different-receipt', $source));
    $denials[] = denied(fn() => $order->addLine(new Material($alpha, 'later'), '1', '10'));
    $denials[] = denied(fn() => PurchaseOrder::normalizeReceiptKey('../../bad'));
    echo json_encode([
        'supplier_code' => $supplier->code(),
        'before' => $before, 'after' => $order->status(),
        'received' => $order->isReceived(),
        'receipt_key' => $order->receiptKey(),
        'line_count' => count($order->lines()),
        'denials' => $denials,
    ], JSON_THROW_ON_ERROR);
}
"""


class CoberturaCondor21Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: real runtime for seven A subcontrols; five absent functions D."""
        rows = rows_from(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(len(A), 7)
        self.assertEqual(len(D), 5)
        for code, row in rows.items():
            with self.subTest(row=code):
                self.assertIn(code, row[0])
                self.assertIn("NO_VERIFICABLE", row[2])
                if code in D:
                    self.assertGreater(len(D[code]), 35)

        doc = php_run(
            PROJECT_PHP, ROOT / "src/Application/Documents/DocumentApprovalRetention.php",
            "App\\Application\\Documents\\DocumentApprovalRetention", data=documentary(),
        )
        history = doc["records"][0]["history"]
        self.assertEqual(["REVISAR", "APROBAR"], [h["action"] for h in history])
        self.assertEqual("APROBADO_EXPLICITO", doc["records"][0]["state"])
        self.assertFalse(doc["automatic_approval"])
        self.assertFalse(doc["records"][0]["retention_is_legal_policy"])

        supplier = php_run(
            SUPPLIER_PHP, ROOT / "src/Domain/Purchasing/Entity/Supplier.php", "  sup-1 ",
        )
        self.assertEqual("SUP-1", supplier["code"])
        self.assertEqual({"error": "denied"}, php_run(
            SUPPLIER_PHP, ROOT / "src/Domain/Purchasing/Entity/Supplier.php", "../invalid",
        ))

        quote = php_run(
            QUOTE_PHP, ROOT / "src/Application/Purchasing/QuotationPreview.php",
            data=quotation(),
        )
        self.assertEqual("SYNTHETIC_PREVIEW", quote["status"])
        self.assertEqual("COP", quote["currency"])
        self.assertEqual(2461, quote["total_cop"])
        self.assertEqual([2461], [line["line_total_cop"] for line in quote["lines"]])
        self.assertFalse(quote["persisted"])
        self.assertFalse(quote["can_order"])
        self.assertFalse(quote["can_pay"])

        domain = php_run(
            DOMAIN_PHP, ROOT / "src/Domain/Purchasing/Entity/Supplier.php",
            str(ROOT / "src/Domain/Purchasing/Entity/PurchaseOrderLine.php"),
            str(ROOT / "src/Domain/Purchasing/Entity/PurchaseOrder.php"),
        )
        self.assertEqual("SUP-1", domain["supplier_code"])
        self.assertEqual("draft", domain["before"])
        self.assertEqual("received", domain["after"])
        self.assertTrue(domain["received"])
        self.assertEqual("fixture-receipt", domain["receipt_key"])
        self.assertEqual(1, domain["line_count"])
        self.assertEqual([True] * 8, domain["denials"])

        assignment = assets.project(assets.fixture())
        self.assertTrue(assignment["valid"])
        self.assertEqual("ASIGNADO", assignment["assets"][0]["state"])
        self.assertEqual("fixture:responsible/agent-a",
                         assignment["assets"][0]["responsible_ref"])
        self.assertFalse(assignment["assets"][0]["can_close"])

        changes = asset_api.execute([
            asset_api.op("POST", payload=asset_api.create()),
            asset_api.op("PATCH", payload={
                "state": "BAJA_REQUIERE_REVISION",
                "evidence_ref": "fixture:evidence/acta",
            }),
            asset_api.op("VIEW"),
        ])
        self.assertEqual("BAJA_REQUIERE_REVISION", changes[1]["state"])
        self.assertEqual("fixture:evidence/acta", changes[2]["evidence_ref"])
        self.assertIn("synthetic.state_changed", changes[-1]["audits"])

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid scopes, authorizations and amounts do not produce effects."""
        matrix = MATRIX.read_text(encoding="utf-8")
        rows = rows_from(matrix)
        first = next(l for l in matrix.splitlines()
                     if l.startswith("| `GEST-M09-S02` · "))
        for corrupt in (
            matrix.replace(first, "", 1),
            matrix + "\n" + first,
            matrix.replace(first, first.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
        ):
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                rows_from(corrupt)
        self.assertEqual(12, len(rows))

        invalid_doc = documentary()
        invalid_doc["records"][0]["events"][0]["actor_ref"] = "fixture:actor/editor"
        result = php_run(
            PROJECT_PHP, ROOT / "src/Application/Documents/DocumentApprovalRetention.php",
            "App\\Application\\Documents\\DocumentApprovalRetention", data=invalid_doc,
        )
        self.assertFalse(result["valid"])
        self.assertEqual([], result["records"])

        scoped_doc = documentary()
        scoped_doc["records"][0]["tenant_ref"] = "tenant-b"
        result = php_run(
            PROJECT_PHP, ROOT / "src/Application/Documents/DocumentApprovalRetention.php",
            "App\\Application\\Documents\\DocumentApprovalRetention", data=scoped_doc,
        )
        self.assertTrue(result["valid"])
        self.assertEqual([], result["records"])

        for change in (
            {"currency": "USD"},
            {"mode": "create_order"},
            {"lines": [{**quotation()["lines"][0], "tenant_ref": "fixture:tenant/beta"}]},
            {"lines": [{**quotation()["lines"][0], "quantity": "0"}]},
            {"lines": quotation()["lines"] * 2},
        ):
            case = {**quotation(), **change}
            with self.subTest(quote=change):
                denied = php_run(
                    QUOTE_PHP, ROOT / "src/Application/Purchasing/QuotationPreview.php",
                    data=case,
                )
                self.assertIn("error", denied)
                self.assertNotIn("total_cop", denied)
        bad_supplier = php_run(
            SUPPLIER_PHP, ROOT / "src/Domain/Purchasing/Entity/Supplier.php", "../../bad",
        )
        self.assertEqual({"error": "denied"}, bad_supplier)

        no_responsible = assets.fixture()
        no_responsible["assets"][0]["responsible_ref"] = None
        self.assertFalse(assets.project(no_responsible)["valid"])
        denied = asset_api.execute([
            asset_api.op("POST", payload=asset_api.create()),
            asset_api.op("PATCH", payload={"state": "BAJA_REQUIERE_REVISION"}),
            asset_api.op("PATCH", payload={
                "state": "BAJA_REQUIERE_REVISION",
                "evidence_ref": "contact@example.test",
            }),
            asset_api.op("VIEW", tenant="tenant-b"),
        ])
        self.assertEqual([{"error": "denied"}] * 2, denied[1:3])
        self.assertIsNone(denied[3])
        self.assertEqual(["synthetic.created"], denied[-1]["audits"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic references only; no network, personal data or live."""
        self.assertEqual(IDS, set(rows_from(MATRIX.read_text(encoding="utf-8"))))
        phase = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", phase["phase"])
        self.assertTrue(phase["d063_attestation"]["no_real_customer_data"])
        self.assertTrue(all("fixture" in statement or len(statement) > 35
                            for statement in A.values()))
        for name in ("QuotationPreview.php", "SupplierService.php"):
            location = ("src/Application/Purchasing/" if name == "QuotationPreview.php"
                        else "src/Application/Purchasing/") + name
            self.assertTrue((ROOT / location).is_file())
        self.assertFalse(any(key in D for key in A))
        self.assertNotIn("CUBIERTO", "\n".join(row[2] for row in
                         rows_from(MATRIX.read_text(encoding="utf-8")).values()))


if __name__ == "__main__":
    unittest.main()

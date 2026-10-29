#!/usr/bin/env python3
"""Condor #1227: five OPS-M04..M06 gaps with synthetic fail-closed checks.

Local code and a real pure-PHP weighted-cost calculation are NOT proof of
complete HTTP integration, CRM, UX, external providers or production.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
GUIDE = "docs/modulos-operativos.md"
INVENTORY = "src/Http/Controller/InventoryController.php"
CUSTOMER = "src/Http/Controller/CustomerController.php"
PURCHASES = "src/Http/Controller/PurchasingController.php"
BRANCH = "src/Http/Controller/BranchApiSupport.php"
COST = "src/Application/Purchasing/MaterialCostService.php"
ORDER = "src/Application/Purchasing/PurchaseOrderService.php"
INV_TEST = "tests/test_inventory_http_negatives.py"
CUSTOMER_TEST = "tests/test_customers_http_isolation.py"
BUY_TEST = "tests/test_purchasing_http_api.py"
COST_TEST = "tests/test_purchasing_weighted_cost_http.py"
SOURCES = (MATRIX, GUIDE, INVENTORY, CUSTOMER, PURCHASES, BRANCH, COST,
           ORDER, INV_TEST, CUSTOMER_TEST, BUY_TEST, COST_TEST)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
TICK = chr(96)
CLAUSES = {
    "OPS-M04-PRUEBA": (
        "registrar sedes, identificar variantes y hacer un ajuste controlado con datos de prueba.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "OPS-M05-PENDIENTE": (
        "CRM avanzado, automatizaciones multicanal e integraciones externas son posibilidades, no capacidades listas.",
        "regresión de consistencia entre controlador, persistencia y permisos del tenant",
    ),
    "OPS-M05-PRUEBA": (
        "establecer categorías, campos obligatorios y permisos de acceso por sede.",
        "regresión de denegación cross-tenant/entidad/sede y prueba de privilegio mínimo",
    ),
    "OPS-M06-PENDIENTE": (
        "la pantalla integral de compras y el costo histórico ponderado dependen de PRs todavía abiertos; no tratarlos como entregados.",
        "prueba de montos, precedencias, moneda COP y rechazo de transacción inválida",
    ),
    "OPS-M06-PRUEBA": (
        "simular proveedor, orden y recepción; contrastar impacto de inventario antes de operar.",
        "regresión de consistencia entre controlador, persistencia y permisos del tenant",
    ),
}
# These checks attest to LOCAL guards only. Absence of runtime test execution
# on exact SHA means no acceptance upgrade and no permission to go live.
EVIDENCE = {
    "OPS-M04-PRUEBA": {
        INVENTORY: ("authorizedBranchScope(", "'inventory.update'", "InventorySource::class"),
        BRANCH: ("authorizedBranchScope(", "$this->currentTenantForUser->resolve($user)"),
        INV_TEST: ("$sourceB->id()", "$variantB->id()", "self::assertResponseStatusCodeSame(404)"),
    },
    "OPS-M05-PENDIENTE": {
        CUSTOMER: ("authorizedBranchScope(", "'customers.view'", "'tenant' => $tenant"),
        CUSTOMER_TEST: ("$categoryB->id()", "self::assertResponseStatusCodeSame(404)"),
    },
    "OPS-M05-PRUEBA": {
        CUSTOMER: ("'customers.create'", "requireCsrf", "commercialRequiredString"),
        BRANCH: ("authorizedBranchScope(",),
        CUSTOMER_TEST: ("$categoryB->id()", "self::assertResponseStatusCodeSame(403)",
                        "self::assertResponseStatusCodeSame(422)"),
    },
    "OPS-M06-PENDIENTE": {
        PURCHASES: ("public function materialCosts(", "'tenant' => $tenant",
                    "$this->materialCosts->calculate($tenant, $material, $orders)"),
        COST: ("'state' => 'UNKNOWN'", "'average_unit_cost' => null", "divideHalfUp"),
        COST_TEST: ("'16.0000'", "'UNKNOWN'", "assertResponseStatusCodeSame(403)"),
    },
    "OPS-M06-PRUEBA": {
        PURCHASES: ("public function createOrder(", "public function receiveOrder(",
                    "'inventory.update'", "$this->branchSource($tenant, $branch, false)"),
        ORDER: ("$order->markReceived(", "wrapInTransaction", "materialInventory->adjust("),
        BUY_TEST: ("$supplierB->id()", "self::assertResponseStatusCodeSame(404)",
                   "self::assertResponseStatusCodeSame(403)"),
    },
}


def sources() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in SOURCES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Fail closed for any missing, ambiguous, malformed or promoted row."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: matrix unreadable")
    result: dict[str, tuple[str, ...]] = {}
    for key, (summary, demand) in CLAUSES.items():
        prefix = "| " + TICK + key + TICK
        hits = [line for line in raw.splitlines()
                if line.startswith(prefix + " ·") or line.startswith(prefix + " |")]
        if len(hits) != 1:
            raise ValueError("UNKNOWN: missing/ambiguous " + key)
        cells = tuple(c.strip() for c in hits[0].strip("|").split("|"))
        if (len(cells) != 5
                or cells[0] != TICK + key + TICK + " · " + TICK + GUIDE + TICK
                or cells[1] != summary
                or cells[2] != TICK + "NO_VERIFICABLE" + TICK
                or f"Falta vincular para {key} " not in cells[3]
                or demand not in cells[3]
                or "no se identificó tríada código+prueba+PR fusionado" not in cells[3]
                or "contraste-cd_ops.md" not in cells[3]
                or cells[4] != "#1"):
            raise ValueError("UNKNOWN: malformed " + key)
        result[key] = cells
    return result


def guide_is_consistent(raw: object) -> bool:
    return type(raw) is str and all(phrase in raw for phrase in (
        "### 4. Inventario y sedes",
        "### 5. Clientes y categorías",
        "### 6. Compras y proveedores",
        "significa que la pantalla esté terminada",
        "alertas automáticas de stock bajo",
        "CRM avanzado, automatizaciones multicanal",
        "costo histórico ponderado dependen de PRs todavía abiertos",
    ))


def partial_evidence(key: object, data: object, probe: object) -> str:
    """Never equate a source-level negative check with production compliance."""
    if key not in CLAUSES or type(data) is not dict or type(probe) is not dict:
        return UNKNOWN
    if set(data) != set(SOURCES) or any(type(data[p]) is not str or not data[p] for p in SOURCES):
        return UNKNOWN
    try:
        matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if not guide_is_consistent(data[GUIDE]):
        return UNKNOWN
    for path, needles in EVIDENCE[key].items():
        if any(needle not in data[path] for needle in needles):
            return UNKNOWN
    if key.startswith("OPS-M06-"):
        try:
            good, missing = probe["known"], probe["missing"]
            valid = (
                good["state"] == "KNOWN"
                and good["average_unit_cost"] == "16.0000"
                and good["total_quantity"] == "5.000000"
                and good["receipt_count"] == 2
                and probe["duplicate"] == good
                and probe["foreign_ignored"] == good
                and missing["state"] == UNKNOWN
                and missing["average_unit_cost"] is None
                and probe["bad_material_denied"] is True
                and probe["zero_quantity_denied"] is True
                and probe["invalid_price_denied"] is True
            )
        except (KeyError, TypeError):
            return UNKNOWN
        if not valid:
            return UNKNOWN
    return GAP


def operational_state(_key: object, _proof: object = None) -> str:
    """No local fixture may declare CRM, purchase UX or live readiness."""
    return UNKNOWN


# Exercise actual MaterialCostService logic on minimal in-memory typed doubles.
# No framework or database, provider credentials, HTTP, sessions or real users.
PHP_PROBE = r'''
namespace App\Domain\Organization\Entity {
  final class Tenant {
    public function __construct(private string $identity) {}
    public function id(): string {return $this->identity;}
  }
}
namespace App\Domain\Production\Entity {
  use App\Domain\Organization\Entity\Tenant;
  final class Material {
    public function __construct(private Tenant $scope, private string $identity) {}
    public function tenant(): Tenant {return $this->scope;}
    public function id(): string {return $this->identity;}
  }
}
namespace App\Domain\Purchasing\Entity {
  use App\Domain\Organization\Entity\Tenant;
  use App\Domain\Production\Entity\Material;
  final class StubLine {
    public function __construct(private Tenant $scope, private Material $material,
      private string $quantity, private string $unitCost) {}
    public function tenant(): Tenant {return $this->scope;}
    public function material(): Material {return $this->material;}
    public function quantity(): string {return $this->quantity;}
    public function unitCost(): string {return $this->unitCost;}
  }
  final class PurchaseOrder {
    public function __construct(private Tenant $scope, private string $identity,
      private bool $received, private array $items) {}
    public function tenant(): Tenant {return $this->scope;}
    public function id(): string {return $this->identity;}
    public function isReceived(): bool {return $this->received;}
    public function lines(): array {return $this->items;}
  }
}
namespace {
  require 'src/Application/Purchasing/MaterialCostService.php';
  $tenant=new \App\Domain\Organization\Entity\Tenant('tenant-synthetic-a');
  $foreign=new \App\Domain\Organization\Entity\Tenant('tenant-synthetic-b');
  $material=new \App\Domain\Production\Entity\Material($tenant,'material-a');
  $a=new \App\Domain\Purchasing\Entity\PurchaseOrder(
    $tenant,'receipt-a',true,[new \App\Domain\Purchasing\Entity\StubLine($tenant,$material,'2','10')]
  );
  $b=new \App\Domain\Purchasing\Entity\PurchaseOrder(
    $tenant,'receipt-b',true,[new \App\Domain\Purchasing\Entity\StubLine($tenant,$material,'3','20')]
  );
  $foreignOrder=new \App\Domain\Purchasing\Entity\PurchaseOrder($foreign,'foreign',true,[]);
  $cost=new \App\Application\Purchasing\MaterialCostService();
  function denied(callable $f): bool {
    try {$f();return false;} catch (\DomainException $e) {return true;}
  }
  $zero=new \App\Domain\Purchasing\Entity\PurchaseOrder(
    $tenant,'bad-zero',true,[new \App\Domain\Purchasing\Entity\StubLine($tenant,$material,'0','10')]
  );
  $invalid=new \App\Domain\Purchasing\Entity\PurchaseOrder(
    $tenant,'bad-price',true,[new \App\Domain\Purchasing\Entity\StubLine($tenant,$material,'2','-1')]
  );
  echo json_encode([
    'known'=>$cost->calculate($tenant,$material,[$a,$b]),
    'missing'=>$cost->calculate($tenant,$material,[]),
    'duplicate'=>$cost->calculate($tenant,$material,[$a,$b,$a]),
    'foreign_ignored'=>$cost->calculate($tenant,$material,[$a,$foreignOrder,$b]),
    'bad_material_denied'=>denied(fn()=>$cost->calculate($foreign,$material,[])),
    'zero_quantity_denied'=>denied(fn()=>$cost->calculate($tenant,$material,[$zero])),
    'invalid_price_denied'=>denied(fn()=>$cost->calculate($tenant,$material,[$invalid]))
  ], JSON_THROW_ON_ERROR);
}
'''


@lru_cache(maxsize=1)
def exercise_php() -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI absent")
    process = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
    )
    if process.returncode or process.stderr.strip():
        raise AssertionError("UNKNOWN: failed PHP probe: " + process.stderr[-350:])
    try:
        parsed = json.loads(process.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid PHP JSON output") from exc
    if type(parsed) is not dict:
        raise AssertionError("UNKNOWN: invalid PHP result")
    return parsed


class BrechasCondor32Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all five matrix rows and the local PHP negative/positive path."""
        data, proof = sources(), exercise_php()
        self.assertEqual(set(matrix_rows(data[MATRIX])), set(CLAUSES))
        self.assertTrue(guide_is_consistent(data[GUIDE]))
        self.assertEqual(set(EVIDENCE), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(GAP, partial_evidence(key, data, proof))
                self.assertEqual(UNKNOWN, operational_state(key, proof))
        self.assertEqual("16.0000", proof["known"]["average_unit_cost"])

    def test_falla_cerrado(self) -> None:
        """AC-02: reject missing, duplicated, forged, or corrupted evidence."""
        data, proof = sources(), exercise_php()
        self.assertEqual(UNKNOWN, partial_evidence("OPS-M07-PRUEBA", data, proof))
        for key in CLAUSES:
            original = next(
                line for line in data[MATRIX].splitlines()
                if line.startswith("| " + TICK + key + TICK + " · ")
            )
            mutants = (
                data[MATRIX].replace(original, "", 1),
                data[MATRIX] + "\n" + original,
                data[MATRIX].replace(original,
                                     original.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            )
            for mutant in mutants:
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(mutant)
                    changed = dict(data, **{MATRIX: mutant})
                    self.assertEqual(UNKNOWN, partial_evidence(key, changed, proof))
            for path in EVIDENCE[key]:
                damaged = dict(data)
                damaged[path] = ""
                self.assertEqual(UNKNOWN, partial_evidence(key, damaged, proof))
            self.assertEqual(UNKNOWN, partial_evidence(key, {}, proof))
            damaged = dict(data)
            damaged[GUIDE] = ""
            self.assertEqual(UNKNOWN, partial_evidence(key, damaged, proof))
        fake = dict(proof)
        fake["known"] = dict(proof["known"], average_unit_cost="0")
        for key in ("OPS-M06-PENDIENTE", "OPS-M06-PRUEBA"):
            self.assertEqual(UNKNOWN, partial_evidence(key, data, fake))

    def test_sin_datos_reales(self) -> None:
        """AC-03: pure CLI fixtures, no customer data, network, or credentials."""
        source = (ROOT / "tests/test_brechas_condor_32.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {
            "socket", "urllib", "requests", "httpx", "playwright", "pymysql", "mysql",
        })
        self.assertEqual(set(SOURCES), {MATRIX, GUIDE, INVENTORY, CUSTOMER, PURCHASES,
                         BRANCH, COST, ORDER, INV_TEST, CUSTOMER_TEST, BUY_TEST, COST_TEST})
        for forbidden in (
            "curl_", "PDO", "file_put_contents", "file_get_contents",
            "shell_exec", "http://", "https://", "DATABASE_URL", "APP_SECRET",
        ):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertTrue(exercise_php()["bad_material_denied"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04 (test): none of these sources certifies live operations."""
        data, proof = sources(), exercise_php()
        for key in CLAUSES:
            self.assertEqual(GAP, partial_evidence(key, data, proof))
            for forged in (
                None, {}, True, {"ci": "success", "sha": "a" * 40},
                {"status": "MERGED", "deployment": "success"},
                {"state": "VALIDATED_IN_PRODUCTION", "sha": "b" * 40},
                {"crm": "ENABLED"}, {"cost_currency": "COP", "stock": "READY"},
            ):
                with self.subTest(key=key, forged=str(forged)):
                    self.assertEqual(UNKNOWN, operational_state(key, forged))
        self.assertTrue(proof["zero_quantity_denied"])
        self.assertTrue(proof["invalid_price_denied"])
        self.assertIsNone(proof["missing"]["average_unit_cost"])


if __name__ == "__main__":
    unittest.main()

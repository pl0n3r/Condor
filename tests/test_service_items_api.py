#!/usr/bin/env python3
"""Condor #865: servicios vendibles, catálogo multi-tenant y pedidos sin stock."""
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PRODUCT = ROOT / "src/Domain/Catalog/Entity/Product.php"
SERVICE = ROOT / "src/Application/Catalog/ServiceItemService.php"
CONTROLLER = ROOT / "src/Http/Controller/ServiceCatalogController.php"
ORDER = ROOT / "src/Application/Orders/OrderService.php"
MIGRATION = ROOT / "migrations/Version20261024233000.php"


class ServiciosVendiblesApiTests(unittest.TestCase):
    def test_acceptance(self):
        """AC-01: servicio vendible sin consumo físico y sin cruce de tenants."""
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para validar AC-01")
        for file in (PRODUCT, SERVICE, CONTROLLER, ORDER, MIGRATION):
            self.assertTrue(file.is_file(), f"Falta archivo reclamado: {file}")
            lint = subprocess.run(
                [php, "-l", str(file)], cwd=ROOT,
                text=True, capture_output=True, check=False, timeout=20,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        product = PRODUCT.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        orders = ORDER.read_text(encoding="utf-8")
        migration = MIGRATION.read_text(encoding="utf-8")

        for key in (
            "KIND_SERVICE", "KIND_PHYSICAL", "function isService()",
            "function durationMinutes()", "function updateServiceDuration(",
            "item_kind", "duration_minutes", "Un servicio no puede permitir",
        ):
            self.assertIn(key, product)
        for key in (
            "new Product(", "new ProductVariant(", "new VariantPrice(",
            "wrapInTransaction(", "'tenant' => $tenant",
            "Product::KIND_SERVICE", "'product' => $products",
            "'price_list_id' => $price->priceList()->id()",
        ):
            self.assertIn(key, service)
        self.assertIn("|| !$priceList->isActive()", service)
        for key in (
            "/api/v1/branches/{branchId}/catalog/services",
            "methods: ['GET']", "methods: ['POST']",
            "'catalog.view'", "'catalog.create'",
            "authorizedBranchScope(", "requireCsrf($request)",
            "'tenant' => $tenant", "ConflictHttpException",
        ):
            self.assertIn(key, controller)
        self.assertIn("!$line->variant()->product()->isService()", orders)
        self.assertIn("isServiceOnlyOrder($order)", orders)
        self.assertEqual(
            3,
            orders.count("if ($reservations === [] && !$this->isServiceOnlyOrder($order))"),
        )
        self.assertIn("reserveOrderLine(", orders)
        self.assertIn("'reserve:'.$order->id().':'.$line->id()", orders)
        self.assertIn("DEFAULT 'physical'", migration)
        self.assertIn("duration_minutes INT DEFAULT NULL", migration)
        self.assertNotIn("DROP TABLE", migration)
        self.assertNotIn("DELETE FROM", migration)

        vendor = ROOT / "vendor/autoload.php"
        if vendor.is_file():
            smoke = r"""
require $argv[1];
$t = new \App\Domain\Organization\Entity\Tenant('Empresa sintética', 'empresa-sintetica');
$p = new \App\Domain\Catalog\Entity\Product($t, 'Servicio', 'servicio', null, false, true, 30);
$g = new \App\Domain\Catalog\Entity\Product($t, 'Físico', 'fisico');
if (!$p->isService() || $p->kind() !== 'service' || $p->durationMinutes() !== 30) exit(1);
if ($g->isService() || $g->kind() !== 'physical' || $g->durationMinutes() !== null) exit(2);
try { $p->setBackorderAllowed(true); exit(3); } catch (\DomainException) {}
try { new \App\Domain\Catalog\Entity\Product($t, 'Mal', 'mal', null, false, true, 0); exit(4); } catch (\DomainException) {}
echo 'OK';
"""
            run = subprocess.run(
                [php, "-r", smoke, str(vendor)], cwd=ROOT,
                text=True, capture_output=True, check=False, timeout=30,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)
            self.assertEqual("OK", run.stdout.strip())

    def test_security_and_input_constraints(self):
        service = SERVICE.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        orders = ORDER.read_text(encoding="utf-8")
        for needle in (
            "$priceList->tenant()->id() !== $tenant->id()",
            "$product->tenant()->id() !== $tenant->id()",
            "$variant->tenant()->id() !== $tenant->id()",
        ):
            self.assertIn(needle, service)
        self.assertIn("!is_int($amount)", controller)
        self.assertIn("!is_int($duration)", controller)
        self.assertIn("'id' => $priceListId", controller)
        self.assertIn("assertSameOrderRequest(", orders)
        self.assertIn("self::normalizeItems($tenant, $items)", orders)


if __name__ == "__main__":
    unittest.main()

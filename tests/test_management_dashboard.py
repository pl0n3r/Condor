#!/usr/bin/env python3
"""#695 management dashboard: real-source, read-only and tenant-bound contracts."""
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER_PATH = ROOT / "src/Http/Controller/ManagementDashboardController.php"
PANEL_PATH = ROOT / "frontend/admin/ManagementDashboard.tsx"
CONTROLLER = CONTROLLER_PATH.read_text(encoding="utf-8")
PANEL = PANEL_PATH.read_text(encoding="utf-8")


PHP_SMOKE = r'''<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManagementDashboardIsolationSmokeTest extends WebTestCase
{
    public function testTenantSourceLinksAndPermissionScopes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(6)));

        // Disposable tenant/branch fixtures: never use users or money from live.
        $tenantA = new Tenant('Gerencia A '.$suffix, 'gerencia-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Gerencia A SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal', 'principal-'.$suffix, $legalA, true);
        $restrictedBranch = new Branch($tenantA, 'Otra sede', 'otra-'.$suffix, $legalA, true);
        $sourceA = new InventorySource(
            $tenantA, $legalA, 'Principal', 'principal-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $owner = new User('mgmt-owner-'.$suffix.'@example.test', 'Owner A');
        $ownerMember = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $viewer = new User('mgmt-viewer-'.$suffix.'@example.test', 'Pedidos únicamente');
        $viewerMember = new Membership($tenantA, $viewer, 'ADMIN');
        $viewerRole = new Role($tenantA, 'Solo pedidos '.$suffix, ['orders.view']);
        $viewerAssignment = new BranchRoleAssignment($viewerMember, $branchA, $viewerRole);
        $restricted = new User('mgmt-restricted-'.$suffix.'@example.test', 'Otra sede');
        $restrictedMember = new Membership($tenantA, $restricted, 'ADMIN');
        $restrictedRole = new Role(
            $tenantA, 'Gerencia de otra sede '.$suffix,
            ['orders.view', 'inventory.view'],
        );
        $restrictedAssignment = new BranchRoleAssignment(
            $restrictedMember, $restrictedBranch, $restrictedRole,
        );

        $productA = new Product($tenantA, 'Prenda '.$suffix, 'prenda-'.$suffix);
        $variantA = new ProductVariant($tenantA, $productA, 'GA-'.$suffix, 'Base');
        $stockA = new InventoryBalance($tenantA, $sourceA, $variantA, 8);
        $materialA = new Material(
            $tenantA, 'MAT-A-'.$suffix, 'Tela', UnitOfMeasure::from('kg'),
        );
        $bomA = new BillOfMaterials($tenantA, $variantA, 1, [[
            'material' => $materialA,
            'quantity' => '1',
            'unit' => UnitOfMeasure::from('kg'),
        ]]);
        $productionA = new ProductionOrder($tenantA, $bomA, $variantA, $sourceA, 1);
        $productionA->complete(1, 'finished-'.$suffix);
        $productionA->saveCostSnapshot([
            'tenant_id' => $tenantA->id(),
            'order_id' => $productionA->id(),
            'source_id' => $sourceA->id(),
            'bom_id' => $bomA->id(),
            'bom_version' => $bomA->version(),
            'completed_quantity' => 1,
            'state' => 'ESTIMATED_COST',
            'total_cost' => '10.0000',
            'unit_cost' => '10.0000',
            'consumptions' => [],
        ]);
        $saleA = new Order(
            $tenantA, $sourceA, null, null, null, 'sale-a-'.$suffix, 'COP', 12500,
        );
        // Fixture simulates a settled payment without a real payment provider.
        (new ReflectionProperty(Order::class, 'paymentStatus'))
            ->setValue($saleA, Order::PAYMENT_PAID);
        $saleA->markConsumed();

        $tenantB = new Tenant('Gerencia B '.$suffix, 'gerencia-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Gerencia B SAS', null, true);
        $branchB = new Branch($tenantB, 'Principal', 'principal-'.$suffix, $legalB, true);
        $sourceB = new InventorySource(
            $tenantB, $legalB, 'Principal B', 'principal-b-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );
        $productB = new Product($tenantB, 'Ajeno '.$suffix, 'ajeno-'.$suffix);
        $variantB = new ProductVariant($tenantB, $productB, 'GB-'.$suffix, 'Base');
        $stockB = new InventoryBalance($tenantB, $sourceB, $variantB, 5);
        $materialB = new Material(
            $tenantB, 'MAT-B-'.$suffix, 'Tela ajena', UnitOfMeasure::from('kg'),
        );
        $bomB = new BillOfMaterials($tenantB, $variantB, 1, [[
            'material' => $materialB,
            'quantity' => '1',
            'unit' => UnitOfMeasure::from('kg'),
        ]]);
        $productionB = new ProductionOrder($tenantB, $bomB, $variantB, $sourceB, 1);
        $productionB->complete(1, 'finished-b-'.$suffix);
        $saleB = new Order(
            $tenantB, $sourceB, null, null, null, 'sale-b-'.$suffix, 'COP', 99999,
        );
        (new ReflectionProperty(Order::class, 'paymentStatus'))
            ->setValue($saleB, Order::PAYMENT_PAID);
        $saleB->markConsumed();

        foreach ([
            $tenantA, $legalA, $branchA, $restrictedBranch, $sourceA,
            $owner, $ownerMember, $viewer, $viewerMember, $viewerRole,
            $viewerAssignment, $restricted, $restrictedMember, $restrictedRole,
            $restrictedAssignment, $productA, $variantA, $stockA, $materialA,
            $bomA, $productionA, $saleA, $tenantB, $legalB, $branchB,
            $sourceB, $productB, $variantB, $stockB, $materialB, $bomB,
            $productionB, $saleB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $baseA = '/api/v1/branches/'.$branchA->id().'/management';
        $baseB = '/api/v1/branches/'.$branchB->id().'/management';
        $client->loginUser($owner);
        $client->request('GET', $baseA.'/dashboard');
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertSame($branchA->id(), $payload['branch_id']);
        self::assertSame([$saleA->id()], array_column($payload['sales']['records'], 'id'));
        self::assertSame([$stockA->id()], array_column($payload['inventory']['records'], 'id'));
        self::assertSame([$productionA->id()], array_column($payload['production']['records'], 'id'));
        self::assertSame(['COP' => 12500], $payload['sales']['by_currency_minor']);
        self::assertSame('UNKNOWN', $payload['inventory']['state']);
        self::assertNull($payload['inventory']['valuation_minor']);
        self::assertSame('ESTIMATED_COST', $payload['production']['records'][0]['cost_state']);
        self::assertSame(
            '10.0000', $payload['production']['records'][0]['estimated_total_cost'],
        );

        foreach (['sales', 'inventory', 'production'] as $section) {
            foreach ($payload[$section]['records'] as $row) {
                self::assertStringStartsWith($baseA.'/sources/', $row['source_url']);
                $client->request('GET', $row['source_url']);
                self::assertResponseStatusCodeSame(200);
                $detail = json_decode(
                    (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
                );
                self::assertSame($row['id'], $detail['id']);
            }
        }
        $client->request('GET', '/admin/management/'.$branchA->id());
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString($saleA->id(), (string) $client->getResponse()->getContent());

        foreach ([
            'sale' => $saleB->id(),
            'inventory' => $stockB->id(),
            'production' => $productionB->id(),
        ] as $kind => $foreignId) {
            $client->request('GET', $baseA.'/sources/'.$kind.'/'.$foreignId);
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', $baseB.'/dashboard');
        self::assertResponseStatusCodeSame(404);

        // Both permissions, on this branch, are required for list and detail.
        $client->loginUser($viewer);
        $client->request('GET', $baseA.'/dashboard');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $baseA.'/sources/sale/'.$saleA->id());
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($restricted);
        $client->request('GET', $baseA.'/dashboard');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $baseA.'/sources/inventory/'.$stockA->id());
        self::assertResponseStatusCodeSame(403);
    }
}
'''


class PanelGerencialV1Tests(unittest.TestCase):
    def test_acceptance(self):
        """Cada cifra usa fuente por ID y no incluye cifras de otro tenant."""
        self.assertIn("class ManagementDashboardController", CONTROLLER)
        self.assertIn("use BranchApiSupport;", CONTROLLER)
        self.assertIn("authorizedBranchScope($branchId, 'orders.view')", CONTROLLER)
        self.assertIn("require($user, $tenant, $branch, 'inventory.view')", CONTROLLER)
        self.assertIn("getRepository(InventorySource::class)->findOneBy([", CONTROLLER)
        self.assertIn("'tenant' => $tenant,", CONTROLLER)
        self.assertIn("'branch' => $branch,", CONTROLLER)
        for entity, scope in (
            ("Order", "'inventorySource' => $source"),
            ("InventoryBalance", "'source' => $source"),
            ("ProductionOrder", "'source' => $source"),
        ):
            with self.subTest(entity=entity):
                self.assertIn("getRepository(" + entity + "::class)", CONTROLLER)
                if entity == "InventoryBalance":
                    self.assertIn("getRepository(InventoryBalance::class)->findBy([", CONTROLLER)
                else:
                    self.assertIn("->createQueryBuilder(", CONTROLLER)
                self.assertIn(scope, CONTROLLER)
                self.assertIn("getRepository(" + entity + "::class)->findOneBy([", CONTROLLER)

        for kind in ("sale", "inventory", "production"):
            with self.subTest(kind=kind):
                self.assertIn("sourceUrl($branchId, '" + kind + "',", CONTROLLER)
                self.assertIn("$kind === '" + kind + "'", CONTROLLER)
        self.assertIn("name: 'api_management_source'", CONTROLLER)
        self.assertIn("return $this->generateUrl('api_management_source'", CONTROLLER)
        self.assertIn("new NotFoundHttpException(", CONTROLLER)
        self.assertIn("SourceLink branchId={branchId} row={", PANEL)
        self.assertIn("authorizedSourceHref", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("data.branch_id !== branchId", PANEL)
        self.assertIn("role=\"alert\"", PANEL)
        self.assertIn("state.status === 'loading'", PANEL)
        self.assertIn("state.status === 'denied'", PANEL)
        self.assertIn("state.status === 'error'", PANEL)
        self.assertIn("type ReactNode", PANEL)
        self.assertIn("setRetry((value) => value + 1)", PANEL)
        self.assertIn("from, through, retry", PANEL)
        self.assertIn("Sin saldos de inventario", PANEL)
        self.assertIn("Sin órdenes terminadas", PANEL)
        self.assertIn("app_management_dashboard", CONTROLLER)

        # AC-01 must execute real Symfony HTTP with disposable tenant A/B
        # fixtures; source-only assertions do not prove isolation or links.
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for real HTTP acceptance")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit dependency required for real HTTP acceptance")
        with tempfile.TemporaryDirectory(prefix="condor-mgmt-http-") as tmp:
            test_file = Path(tmp) / "ManagementDashboardIsolationSmokeTest.php"
            test_file.write_text(PHP_SMOKE, encoding="utf-8")
            syntax = subprocess.run(
                [php, "-l", str(test_file)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
            )
            self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
            result = subprocess.run(
                [str(runner), "--filter", "testTenantSourceLinksAndPermissionScopes", str(test_file)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertIn("OK (1 test,", result.stdout + result.stderr)

    def test_security_and_financial_integrity(self):
        self.assertIn("methods: ['GET']", CONTROLLER)
        self.assertIn("sale.paymentStatus = :paid", CONTROLLER)
        self.assertIn("sale.fulfillmentStatus = :consumed", CONTROLLER)
        self.assertIn("sale.orderStatus <> :cancelled", CONTROLLER)
        self.assertIn("sale.createdAt >= :from", CONTROLLER)
        self.assertIn("sale.createdAt < :until", CONTROLLER)
        self.assertIn("production.completedAt >= :from", CONTROLLER)
        self.assertIn("production.completedAt < :until", CONTROLLER)
        self.assertIn("production.status = :complete", CONTROLLER)
        self.assertIn("ksort($byCurrency, SORT_STRING)", CONTROLLER)
        self.assertIn("'by_currency_minor' => $byCurrency", CONTROLLER)
        self.assertIn("'valuation_minor' => null", CONTROLLER)
        self.assertIn("'valuation_state' => 'UNKNOWN'", CONTROLLER)
        self.assertIn("->costSnapshot()", CONTROLLER)
        self.assertIn("self::isSavedCost($saved, $tenant->id(), $order->id())", CONTROLLER)
        self.assertIn("'ESTIMATED_COST'", CONTROLLER)
        self.assertIn("'currency' => null", CONTROLLER)
        self.assertIn("El período debe estar ordenado", CONTROLLER)
        self.assertIn("htmlspecialchars(", CONTROLLER)
        self.assertNotIn("profit_minor", CONTROLLER)
        self.assertNotIn("tenantId: string", PANEL)
        self.assertNotIn("POST", CONTROLLER)
        self.assertNotIn("DELETE", CONTROLLER)
        self.assertNotIn("hardcodedPrice", PANEL)

    def test_php_controller_syntax(self):
        php = shutil.which("php")
        if not php:
            self.skipTest("PHP CLI not present; PHP syntax is checked by Backend PHP CI")
        check = subprocess.run(
            [php, "-l", str(CONTROLLER_PATH)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)


if __name__ == "__main__":
    unittest.main()

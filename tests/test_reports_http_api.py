#!/usr/bin/env python3
"""Condor #752: real Symfony HTTP/Doctrine acceptance for bounded branch CSV."""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/ReportsController.php"
READER = ROOT / "src/Application/Reporting/TenantReportRecordsReader.php"

# Generated inside an external temporary directory, not a fourth V3 claim.
# PHPUnit boots Symfony in test mode and persists synthetic isolated records.
PHP_TEST = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\Supplier;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReportsHttpContractGeneratedTest extends WebTestCase
{
    public function testCsvFromActualScopedRecords(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Reportes A '.$suffix, 'reports-a-'.$suffix);
        $legal = new LegalEntity($tenant, 'Reports A SAS', null, true);
        $branch = new Branch($tenant, 'A', 'a-'.$suffix, $legal);
        $source = new InventorySource($tenant, $legal, 'Principal', 'source-a-'.$suffix, InventorySource::TYPE_BRANCH, $branch);
        $otherBranch = new Branch($tenant, 'B', 'b-'.$suffix, $legal);
        $otherSource = new InventorySource($tenant, $legal, 'B', 'source-b-'.$suffix, InventorySource::TYPE_BRANCH, $otherBranch);
        $owner = new User('reports-owner-'.$suffix.'@example.test', 'Dueño A');
        $membership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $viewer = new User('reports-viewer-'.$suffix.'@example.test', 'Visor A');
        $viewerMembership = new Membership($tenant, $viewer, 'STAFF');
        $role = new Role($tenant, 'Solo inventario '.$suffix, ['inventory.view']);
        $assignment = new BranchRoleAssignment($viewerMembership, $branch, $role);
        $product = new Product($tenant, 'Producto A', 'product-a-'.$suffix);
        $variant = new ProductVariant($tenant, $product, 'SKU-'.$suffix, 'Única');
        $balance = new InventoryBalance($tenant, $source, $variant, 17);
        $order = new Order($tenant, $source, null, null, null, 'report-order-'.$suffix, 'COP', 2500);
        $otherOrder = new Order($tenant, $otherSource, null, null, null, 'report-branch-b-'.$suffix, 'COP', 9000);
        $supplier = new Supplier($tenant, 'SUP-'.$suffix, 'Proveedor A');
        $material = new Material($tenant, 'MAT-'.$suffix, 'Material', new UnitOfMeasure('unit'));
        $purchase = new PurchaseOrder($tenant, $legal, $supplier);
        $purchase->addLine($material, '2', '7.2500');
        $purchase->markReceived('receipt-'.$suffix, $source->id());

        $foreignTenant = new Tenant('Reportes B '.$suffix, 'reports-b-'.$suffix);
        $foreignLegal = new LegalEntity($foreignTenant, 'Reports B SAS', null, true);
        $foreignBranch = new Branch($foreignTenant, 'B', 'foreign-'.$suffix, $foreignLegal);
        $foreignSource = new InventorySource($foreignTenant, $foreignLegal, 'B', 'foreign-source-'.$suffix, InventorySource::TYPE_BRANCH, $foreignBranch);
        $foreignOwner = new User('reports-foreign-'.$suffix.'@example.test', 'Dueño B');
        $foreignMembership = new Membership($foreignTenant, $foreignOwner, Membership::ROLE_OWNER);
        $foreignOrder = new Order($foreignTenant, $foreignSource, null, null, null, 'report-foreign-'.$suffix, 'COP', 7777);
        foreach ([
            $tenant, $legal, $branch, $source, $otherBranch, $otherSource, $owner, $membership,
            $viewer, $viewerMembership, $role, $assignment, $product, $variant, $balance,
            $order, $otherOrder, $supplier, $material, $purchase,
            $foreignTenant, $foreignLegal, $foreignBranch, $foreignSource,
            $foreignOwner, $foreignMembership, $foreignOrder,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $url = '/api/v1/branches/'.$branch->id().'/reports/';
        $period = '?from=2026-10-01&through=2026-10-31';
        $client->loginUser($owner);
        $identifiers = [
            'inventory' => [$balance->id(), 'reserved_quantity'],
            'sales' => [$order->id(), 'total_amount_minor'],
            'purchases' => [$purchase->id(), 'total_cost_minor'],
        ];
        foreach ($identifiers as $kind => [$id, $column]) {
            $client->request('GET', $url.$kind.'.csv'.$period);
            self::assertResponseStatusCodeSame(200);
            $response = $client->getResponse();
            self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
            self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $body = (string) $response->getContent();
            self::assertStringContainsString($id, $body);
            self::assertStringContainsString($column, $body);
            self::assertStringNotContainsString($foreignOrder->id(), $body);
            self::assertStringNotContainsString($otherOrder->id(), $body);
            if ($kind === 'purchases') {
                self::assertStringContainsString('1450', $body);
            }
            $client->request('GET', $url.$kind.'.csv?from=2026-09-01&through=2026-09-30');
            self::assertResponseStatusCodeSame(200);
            self::assertCount(1, array_filter(explode("\n", trim((string) $client->getResponse()->getContent()))));
        }
        foreach ([
            'unknown.csv'.$period => 404,
            'sales.csv?from=2026-02-30&through=2026-10-31' => 400,
            'sales.csv?from=2026-10-31&through=2026-10-01' => 400,
            'sales.csv'.$period.'&tenant='.$foreignTenant->id() => 400,
        ] as $urlPart => $status) {
            $client->request('GET', $url.$urlPart);
            self::assertResponseStatusCodeSame($status);
        }
        $client->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/reports/sales.csv'.$period);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($viewer);
        $client->request('GET', $url.'inventory.csv'.$period);
        self::assertResponseStatusCodeSame(200);
        $client->request('GET', $url.'purchases.csv'.$period);
        self::assertResponseStatusCodeSame(200);
        $client->request('GET', $url.'sales.csv'.$period);
        self::assertResponseStatusCodeSame(403);

        $client->restart();
        $client->request('GET', $url.'sales.csv'.$period);
        self::assertResponseStatusCodeSame(401);
    }
}
"""


class ReportesHttpApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required for HTTP acceptance")
        for path in (CONTROLLER, READER):
            self.assertTrue(path.is_file(), str(path))
            lint = subprocess.run(
                [php, "-l", str(path)], capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        controller = CONTROLLER.read_text(encoding="utf-8")
        reader = READER.read_text(encoding="utf-8")
        for snippet in (
            "use BranchApiSupport;", "authorizedBranchScope(",
            "'orders.view'", "'inventory.view'", "TYPE_BRANCH",
            "TenantCsvReportService", "Cache-Control", "no-store",
        ):
            self.assertIn(snippet, controller)
        for snippet in (
            "InventoryBalance::class", "Order::class", "PurchaseOrder::class",
            "legalEntity = :legal", "setMaxResults(self::ROW_LIMIT + 1)",
            "receiptSourceId = :sourceId",
        ):
            self.assertIn(snippet, reader)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit Symfony integration runner required")
        with tempfile.TemporaryDirectory(prefix="condor-reports-http-") as directory:
            php_test = Path(directory) / "ReportsHttpContractGeneratedTest.php"
            php_test.write_text(PHP_TEST, encoding="utf-8")
            result = subprocess.run(
                [str(runner), "--filter", "testCsvFromActualScopedRecords", str(php_test)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

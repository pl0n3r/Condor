#!/usr/bin/env python3
"""Acceptance: authenticated weighted historical material costs over purchasing HTTP."""

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PurchasingController.php"
COST_SERVICE = ROOT / "src/Application/Purchasing/MaterialCostService.php"
BRANCH_SUPPORT = ROOT / "src/Http/Controller/BranchApiSupport.php"

PHP_SMOKE = r'''<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PurchasingMaterialCostSmokeTest extends WebTestCase
{
    public function testAuthenticatedWeightedAverageUnknownAndIsolation(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $tenantA = new Tenant('Cost A '.$suffix, 'cost-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Cost A SAS '.$suffix, null, true);
        $branchA = new Branch($tenantA, 'Principal', 'principal-'.$suffix, $legalA, true);
        $sourceA = new InventorySource(
            $tenantA, $legalA, 'Principal', 'principal-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $supplierA = new Supplier($tenantA, 'SUP-'.$suffix, 'Proveedor propio');
        $known = new Material($tenantA, 'MAT-K-'.$suffix, 'Tela', UnitOfMeasure::from('m'));
        $unknown = new Material($tenantA, 'MAT-U-'.$suffix, 'Hilo', UnitOfMeasure::from('m'));

        $tenantB = new Tenant('Cost B '.$suffix, 'cost-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Cost B SAS '.$suffix, null, true);
        $branchB = new Branch($tenantB, 'Ajena', 'ajena-'.$suffix, $legalB, true);
        $foreign = new Material($tenantB, 'MAT-B-'.$suffix, 'Ajena', UnitOfMeasure::from('m'));

        $owner = new User('cost-owner-'.$suffix.'@example.test', 'Owner');
        $membership = new Membership($tenantA, $owner, Membership::ROLE_OWNER);

        $first = new PurchaseOrder($tenantA, $legalA, $supplierA);
        $first->addLine($known, '2', '10');
        $first->markReceived('cost-receipt-first-'.$suffix, $sourceA->id());

        $second = new PurchaseOrder($tenantA, $legalA, $supplierA);
        $second->addLine($known, '3', '20');
        $second->markReceived('cost-receipt-second-'.$suffix, $sourceA->id());

        $pending = new PurchaseOrder($tenantA, $legalA, $supplierA);
        $pending->addLine($known, '100', '999');

        foreach ([
            $tenantA, $legalA, $branchA, $sourceA, $supplierA,
            $known, $unknown, $tenantB, $legalB, $branchB, $foreign,
            $owner, $membership, $first, $second, $pending,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $url = '/api/v1/branches/'.$branchA->id().'/purchasing/material-costs';
        $client->loginUser($owner);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('material_costs', $payload);
        $byMaterial = array_column($payload['material_costs'], null, 'material_id');
        self::assertArrayHasKey($known->id(), $byMaterial);
        self::assertArrayHasKey($unknown->id(), $byMaterial);
        self::assertArrayNotHasKey($foreign->id(), $byMaterial);

        self::assertSame('KNOWN', $byMaterial[$known->id()]['state']);
        self::assertSame('16.0000', $byMaterial[$known->id()]['average_unit_cost']);
        self::assertSame('5.000000', $byMaterial[$known->id()]['total_quantity']);
        self::assertSame(2, $byMaterial[$known->id()]['receipt_count']);

        self::assertSame('UNKNOWN', $byMaterial[$unknown->id()]['state']);
        self::assertNull($byMaterial[$unknown->id()]['average_unit_cost']);
        self::assertSame(0, $byMaterial[$unknown->id()]['receipt_count']);

        $client->request(
            'GET',
            '/api/v1/branches/'.$branchB->id().'/purchasing/material-costs',
        );
        self::assertResponseStatusCodeSame(404);
    }
}
'''


class PurchasingWeightedCostHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        service = COST_SERVICE.read_text(encoding="utf-8")
        self.assertIn("/purchasing/material-costs", controller)
        self.assertIn("public function materialCosts(", controller)
        self.assertIn("$this->materialCosts->calculate($tenant, $material, $orders)", controller)
        self.assertIn("'material_costs' => $costs", controller)
        self.assertIn("'material_id' => $material->id()", controller)
        self.assertIn("average_unit_cost", service)
        self.assertIn("'UNKNOWN'", service)
        self.assertIn("intdiv(", service)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para el contrato real de compras")
        syntax = subprocess.run(
            [php, "-l", str(CONTROLLER)], capture_output=True, text=True, check=False,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "PHPUnit instalado requerido")

        with tempfile.TemporaryDirectory(prefix="condor-weighted-http-") as tempdir:
            smoke = Path(tempdir) / "PurchasingMaterialCostSmokeTest.php"
            smoke.write_text(PHP_SMOKE, encoding="utf-8")
            result = subprocess.run(
                [str(runner), str(smoke)], cwd=ROOT,
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_denies_cross_tenant_or_unauthorized_scope(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        support = BRANCH_SUPPORT.read_text(encoding="utf-8")
        method = controller.split("public function materialCosts(", 1)[1].split(
            "public function createOrder(", 1
        )[0]
        self.assertIn("authorizedBranchScope($branchId, 'inventory.view')", method)
        self.assertIn("$this->branchSource($tenant, $branch, false)", method)
        self.assertIn("['tenant' => $tenant]", method)
        self.assertIn("'tenant' => $tenant", support)
        self.assertIn("$this->authorization->require(", support)
        self.assertNotIn("tenant_id", method)
        self.assertNotIn("floatval", method)
        self.assertNotIn("number_format", method)


if __name__ == "__main__":
    unittest.main()

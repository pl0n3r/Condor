#!/usr/bin/env python3
"""Condor #730: real Symfony HTTP negatives with isolated synthetic tenants.

The PHPUnit fixture is generated in a temporary directory so the reservation
only needs tests/test_inventory_http_negatives.py. No network or customer data.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/InventoryController.php"

HTTP_NEGATIVES = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InventoryHttpNegativesSmokeTest extends WebTestCase
{
    public function testNegativeQuantitiesAndForeignScopesKeepStockUnchanged(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));

        $tenantA = new Tenant('Inventario A '.$suffix, 'inv-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Inventario A SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal', 'principal-'.$suffix, $legalA, true);
        $owner = new User('inventory-'.$suffix.'@example.test', 'Owner');
        $membership = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $productA = new Product($tenantA, 'Producto A', 'producto-a-'.$suffix);
        $variantA = new ProductVariant($tenantA, $productA, 'SKU-A-'.$suffix, 'Principal');
        $sourceA = new InventorySource(
            $tenantA, $legalA, 'Principal', 'principal-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $sourceTo = new InventorySource(
            $tenantA, $legalA, 'Reserva', 'reserva-'.$suffix,
            InventorySource::TYPE_LOGICAL,
        );

        $tenantB = new Tenant('Inventario B '.$suffix, 'inv-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Inventario B SAS', null, true);
        $branchB = new Branch($tenantB, 'Ajena', 'ajena-'.$suffix, $legalB, true);
        $productB = new Product($tenantB, 'Producto B', 'producto-b-'.$suffix);
        $variantB = new ProductVariant($tenantB, $productB, 'SKU-B-'.$suffix, 'Ajena');
        $sourceB = new InventorySource(
            $tenantB, $legalB, 'Ajena', 'ajena-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );

        foreach ([
            $tenantA, $legalA, $branchA, $owner, $membership,
            $productA, $variantA, $sourceA, $sourceTo,
            $tenantB, $legalB, $branchB, $productB, $variantB, $sourceB,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];
        $base = '/api/v1/branches/'.$branchA->id().'/inventory';

        // Positive control: a genuine persisted quantity makes false-success
        // negatives visible. No movement in any denied attempt is accepted.
        $client->jsonRequest('POST', $base.'/adjustments', [
            'source_id' => $sourceA->id(),
            'variant_id' => $variantA->id(),
            'delta' => 5,
            'reason' => 'Saldo sintético inicial',
            'idempotency_key' => 'inv-initial-'.$suffix,
        ], $headers);
        self::assertResponseStatusCodeSame(201);

        $assertUnchanged = static function () use (
            $manager, $tenantA, $sourceA, $sourceTo, $variantA
        ): void {
            $db = $manager->getConnection();
            $balance = $db->fetchOne(
                'SELECT quantity FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
                [$tenantA->id(), $sourceA->id(), $variantA->id()],
            );
            self::assertSame(5, (int) $balance);
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ?',
                [$tenantA->id(), $sourceTo->id()],
            ));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_inventory_movement '
                .'WHERE tenant_id = ?',
                [$tenantA->id()],
            ));
        };
        $assertUnchanged();

        $cases = [
            // Transfer quantity must be positive, never a negative movement.
            ['transfers', [
                'source_from_id' => $sourceA->id(),
                'source_to_id' => $sourceTo->id(),
                'variant_id' => $variantA->id(),
                'quantity' => -1,
                'idempotency_key' => 'inv-transfer-neg-'.$suffix,
            ], 422],
            // Negative adjustment is valid only with sufficient stock.
            ['adjustments', [
                'source_id' => $sourceA->id(),
                'variant_id' => $variantA->id(),
                'delta' => -6,
                'reason' => 'No permitir stock negativo',
                'idempotency_key' => 'inv-adjust-neg-'.$suffix,
            ], 422],
            ['adjustments', [
                'source_id' => $sourceA->id(),
                'variant_id' => $variantB->id(),
                'delta' => 1,
                'reason' => 'Variante tenant ajeno',
                'idempotency_key' => 'inv-adjust-foreign-variant-'.$suffix,
            ], 404],
            ['adjustments', [
                'source_id' => $sourceB->id(),
                'variant_id' => $variantA->id(),
                'delta' => 1,
                'reason' => 'Sede tenant ajeno',
                'idempotency_key' => 'inv-adjust-foreign-source-'.$suffix,
            ], 404],
            ['transfers', [
                'source_from_id' => $sourceA->id(),
                'source_to_id' => $sourceB->id(),
                'variant_id' => $variantA->id(),
                'quantity' => 1,
                'idempotency_key' => 'inv-transfer-foreign-source-'.$suffix,
            ], 404],
        ];
        foreach ($cases as [$route, $payload, $expectedStatus]) {
            $client->jsonRequest('POST', $base.'/'.$route, $payload, $headers);
            self::assertResponseStatusCodeSame($expectedStatus);
            $error = json_decode(
                (string) $client->getResponse()->getContent(),
                true, 512, JSON_THROW_ON_ERROR,
            );
            self::assertSame($expectedStatus, $error['status']);
            self::assertSame(
                $expectedStatus === 404 ? 'not_found' : 'validation_error',
                $error['error'],
            );
            $assertUnchanged();
        }

        // Even GET on a foreign branch cannot leak a stock snapshot.
        $client->request(
            'GET', '/api/v1/branches/'.$branchB->id().'/inventory',
        );
        self::assertResponseStatusCodeSame(404);
        $assertUnchanged();
    }
}
"""


class InventarioNegativasHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio para contrato HTTP")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit real es obligatorio para contrato HTTP")

        with tempfile.TemporaryDirectory(prefix="condor-inventory-http-") as directory:
            fixture = Path(directory) / "InventoryHttpNegativesSmokeTest.php"
            fixture.write_text(HTTP_NEGATIVES, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(runner), "--no-progress", str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertRegex(result.stdout + result.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

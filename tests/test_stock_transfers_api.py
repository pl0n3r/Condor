#!/usr/bin/env python3
"""AC-01 Condor #862: transferencias entre sedes, ledger e aislamiento reales.

La implementación base de /inventory/transfers sigue disponible. La ruta
branch-transfers exige además stock físico no negativo incluso con backorder.
La prueba HTTP usa tenants, sedes y usuarios sintéticos en MariaDB de CI.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Inventory/StockTransferService.php"
CONTROLLER = ROOT / "src/Http/Controller/StockTransferController.php"
INVENTORY = ROOT / "src/Application/Inventory/InventoryService.php"

PHP_HTTP = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockTransfersApiSmokeTest extends WebTestCase
{
    public function testTwoMovementsReplayAndDenyCrossBranchAndTenant(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Transfer A '.$suffix, 'transfer-a-'.$suffix);
        $legal = new LegalEntity($tenant, 'Transfer A SAS', null, true);
        $branchA = new Branch($tenant, 'Origen', 'origen-'.$suffix, $legal, true);
        $branchB = new Branch($tenant, 'Destino', 'destino-'.$suffix, $legal, true);
        $from = new InventorySource(
            $tenant, $legal, 'Origen', 'origen-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $to = new InventorySource(
            $tenant, $legal, 'Destino', 'destino-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );
        $product = new Product($tenant, 'Producto '.$suffix, 'producto-'.$suffix);
        // Stock transfers must NOT overdraw even if commercial backorder is on.
        $product->setBackorderAllowed(true);
        $variant = new ProductVariant($tenant, $product, 'SKU-'.$suffix, 'Variante');
        $balance = new InventoryBalance($tenant, $from, $variant, 3);
        $owner = new User('transfer-owner-'.$suffix.'@example.test', 'Owner');
        $membership = new Membership($tenant, $owner, Membership::ROLE_OWNER);

        $other = new Tenant('Transfer B '.$suffix, 'transfer-b-'.$suffix);
        $otherLegal = new LegalEntity($other, 'Transfer B SAS', null, true);
        $otherBranch = new Branch($other, 'Ajena', 'ajena-'.$suffix, $otherLegal, true);
        $foreign = new InventorySource(
            $other, $otherLegal, 'Ajena', 'ajena-'.$suffix,
            InventorySource::TYPE_BRANCH, $otherBranch,
        );

        $limited = new User('transfer-limited-'.$suffix.'@example.test', 'Editor');
        $limitedMember = new Membership($tenant, $limited, 'ADMIN');
        $role = new Role($tenant, 'Sólo origen '.$suffix, ['inventory.update']);
        $assignment = new BranchRoleAssignment($limitedMember, $branchA, $role);
        foreach ([
            $tenant, $legal, $branchA, $branchB, $from, $to,
            $product, $variant, $balance, $owner, $membership,
            $other, $otherLegal, $otherBranch, $foreign,
            $limited, $limitedMember, $role, $assignment,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $url = '/api/v1/branches/'.$branchA->id().'/inventory/branch-transfers';
        $payload = [
            'source_from_id' => $from->id(),
            'source_to_id' => $to->id(),
            'variant_id' => $variant->id(),
            'quantity' => 2,
            'idempotency_key' => 'transfer-'.$suffix,
        ];
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        $client->jsonRequest('POST', $url, $payload, $headers);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('completed', $first['transfer']['status']);
        self::assertSame(2, $first['transfer']['quantity']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->jsonRequest('POST', $url, $payload, $headers);
        self::assertResponseStatusCodeSame(200);
        $replayed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($first['transfer']['id'], $replayed['transfer']['id']);

        $conn = $manager->getConnection();
        $movementCount = fn (): int => (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_movement WHERE tenant_id = ? AND transfer_id = ?',
            [$tenant->id(), $first['transfer']['id']],
        );
        self::assertSame(2, $movementCount());
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT quantity FROM condor_inventory_balance WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
            [$tenant->id(), $from->id(), $variant->id()],
        ));
        self::assertSame(2, (int) $conn->fetchOne(
            'SELECT quantity FROM condor_inventory_balance WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
            [$tenant->id(), $to->id(), $variant->id()],
        ));
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM condor_audit_event WHERE tenant_id = ? AND action = ?',
            [$tenant->id(), 'inventory.transferred'],
        ));

        // Neither a forged tenant ID nor an unauthorized destination is exposed.
        $client->jsonRequest('POST', $url, $payload + [], $headers);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('POST', $url, array_replace($payload, [
            'source_to_id' => $foreign->id(),
            'idempotency_key' => 'foreign-'.$suffix,
        ]), $headers);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($limited);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $limitedCsrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        $client->jsonRequest('POST', $url, array_replace($payload, [
            'quantity' => 1,
            'idempotency_key' => 'unauthorized-'.$suffix,
        ]), ['HTTP_X_CSRF_TOKEN' => $limitedCsrf]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, $movementCount());

        // From now on stock is 1; attempting to move 2 must rollback even
        // though Product::allowsBackorder() is true.
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $ownerCsrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        $client->jsonRequest('POST', $url, array_replace($payload, [
            'idempotency_key' => 'overdraw-'.$suffix,
        ]), ['HTTP_X_CSRF_TOKEN' => $ownerCsrf]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, $movementCount());
        // No phantom movement, transfer record, destination quantity or audit
        // may survive a rejected/overdrawn branch transfer.
        self::assertSame(2, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_movement WHERE tenant_id = ?',
            [$tenant->id()],
        ));
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_transfer WHERE tenant_id = ?',
            [$tenant->id()],
        ));
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM condor_audit_event WHERE tenant_id = ? AND action = ?',
            [$tenant->id(), 'inventory.transferred'],
        ));
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT quantity FROM condor_inventory_balance WHERE tenant_id = ? AND source_id = ?',
            [$tenant->id(), $from->id()],
        ));
        self::assertSame(2, (int) $conn->fetchOne(
            'SELECT quantity FROM condor_inventory_balance WHERE tenant_id = ? AND source_id = ?',
            [$tenant->id(), $to->id()],
        ));
    }
}
"""


class TransferenciasSedesApiTests(unittest.TestCase):
    def test_acceptance(self):
        self.assertTrue(SERVICE.is_file(), "Falta servicio #862")
        self.assertTrue(CONTROLLER.is_file(), "Falta HTTP #862")
        service = SERVICE.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        inventory = INVENTORY.read_text(encoding="utf-8")

        # Safety-critical paths must remain attached to transactional and
        # authorized implementation, not merely successful HTTP responses.
        for needle in (
            "wrapInTransaction(",
            "LockMode::PESSIMISTIC_WRITE",
            "getRepository(InventoryBalance::class)",
            "balance->quantity() - $balance->reservedQuantity() < $quantity",
            "$this->inventory->transfer(",
            "getRepository(InventoryTransfer::class)->findOneBy(",
            "InventorySource::TYPE_BRANCH",
        ):
            self.assertIn(needle, service)
        for needle in (
            "requireCsrf($request)",
            "authorizedBranchScope(",
            "'inventory.update'",
            "'tenant' => $tenant",
            "'legalEntity' => $legalEntity",
            "InventorySource::TYPE_BRANCH",
            "$this->authorization->require(",
            "$this->transfers->transfer(",
            "'inventory.transferred'",
            "'Cache-Control', 'no-store'",
        ):
            self.assertIn(needle, controller)
        for needle in (
            "InventoryMovement::TYPE_TRANSFER_OUT",
            "InventoryMovement::TYPE_TRANSFER_IN",
            "wrapInTransaction(",
        ):
            self.assertIn(needle, inventory)

        php = shutil.which("php")
        if php:
            for path in (SERVICE, CONTROLLER):
                result = subprocess.run(
                    [php, "-l", str(path)],
                    cwd=ROOT, capture_output=True, text=True, timeout=15,
                )
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)

        # Real HTTP + DB assertions run whenever Composer/PHPUnit is present
        # (as in the Condor backend CI). A Python-only runner can verify the
        # source contract but is not evidence of successful E2E execution.
        runner = ROOT / "vendor/bin/simple-phpunit"
        if not runner.is_file():
            return

        with tempfile.TemporaryDirectory(prefix="condor-transfer-862-") as tmp:
            script = Path(tmp) / "StockTransfersApiSmokeTest.php"
            script.write_text(PHP_HTTP, encoding="utf-8")
            result = subprocess.run(
                [str(runner), "-c", str(ROOT / "phpunit.xml"), str(script)],
                cwd=ROOT,
                env=dict(os.environ, APP_ENV="test"),
                capture_output=True, text=True, timeout=150,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

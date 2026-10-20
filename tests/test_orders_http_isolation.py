#!/usr/bin/env python3
"""Condor #728 AC-01: authenticated order HTTP isolation, synthetic tenants."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/OrderController.php"

HTTP_SMOKE = r'''<?php
declare(strict_types=1);

use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderScopeSmokeTest extends WebTestCase
{
    public function testTenantOrdersAreHiddenAndMutationsDenied(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = strtolower(bin2hex(random_bytes(6)));

        $tenantA = new Tenant('Pedidos A '.$suffix, 'pedidos-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Pedidos A SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal', 'principal-'.$suffix, $legalA, true);
        $sourceA = new InventorySource(
            $tenantA, $legalA, 'Sede A', 'sede-a-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $priceA = new PriceList($tenantA, 'Lista A', 'lista-a-'.$suffix);
        $orderA = new Order(
            $tenantA, $sourceA, null, null, null, 'order-a-'.$suffix, 'COP', 0,
        );
        $ownerA = new User('order-owner-a-'.$suffix.'@example.test', 'Owner A');
        $membershipA = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);
        $viewerA = new User('order-viewer-a-'.$suffix.'@example.test', 'Viewer A');
        $viewerMember = new Membership($tenantA, $viewerA, 'STAFF');
        $viewRole = new Role($tenantA, 'Sólo ver pedidos '.$suffix, ['orders.view']);
        $assignment = new BranchRoleAssignment($viewerMember, $branchA, $viewRole);

        $tenantB = new Tenant('Pedidos B '.$suffix, 'pedidos-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Pedidos B SAS', null, true);
        $branchB = new Branch($tenantB, 'Principal', 'principal-'.$suffix, $legalB, true);
        $sourceB = new InventorySource(
            $tenantB, $legalB, 'Sede B', 'sede-b-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );
        $priceB = new PriceList($tenantB, 'Lista B', 'lista-b-'.$suffix);
        $orderB = new Order(
            $tenantB, $sourceB, null, null, null, 'order-b-'.$suffix, 'COP', 0,
        );
        $ownerB = new User('order-owner-b-'.$suffix.'@example.test', 'Owner B');
        $membershipB = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);

        foreach ([
            $tenantA, $legalA, $branchA, $sourceA, $priceA, $orderA,
            $ownerA, $membershipA, $viewerA, $viewerMember, $viewRole, $assignment,
            $tenantB, $legalB, $branchB, $sourceB, $priceB, $orderB,
            $ownerB, $membershipB,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $urlA = '/api/v1/branches/'.$branchA->id().'/orders';
        $urlB = '/api/v1/branches/'.$branchB->id().'/orders';

        $client->loginUser($ownerA);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        // A local list must not include foreign orders or inventory sources.
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        );
        $ids = array_column($body['orders'], 'id');
        self::assertContains($orderA->id(), $ids);
        self::assertNotContains($orderB->id(), $ids);
        self::assertCount(1, $ids);
        self::assertNotContains($sourceB->id(), array_column($body['sources'], 'id'));

        // Foreign branch and foreign order IDs must fail closed, including writes.
        $client->request('GET', $urlB);
        self::assertResponseStatusCodeSame(404);
        foreach (['cancel', 'consume'] as $action) {
            $client->request('POST', $urlA.'/'.$orderB->id().'/'.$action, server: $headers);
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', $urlB.'/'.$orderB->id().'/'.$action, server: $headers);
            self::assertResponseStatusCodeSame(404);
        }

        $client->jsonRequest('POST', $urlA, [
            'inventory_source_id' => $sourceB->id(),
            'price_list_id' => $priceA->id(),
            'idempotency_key' => 'foreign-source-'.$suffix,
            'items' => [],
        ], $headers);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $urlA, [
            'inventory_source_id' => $sourceA->id(),
            'price_list_id' => $priceB->id(),
            'idempotency_key' => 'foreign-price-'.$suffix,
            'items' => [],
        ], $headers);
        self::assertResponseStatusCodeSame(404);

        // A delegated viewer can read local orders but may not mutate.
        $client->loginUser($viewerA);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertSame([$orderA->id()], array_column($body['orders'], 'id'));
        $client->request('GET', $urlB);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $urlA.'/'.$orderA->id().'/cancel', server: $headers);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $urlA.'/'.$orderA->id().'/consume', server: $headers);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $urlA, [], $headers);
        self::assertResponseStatusCodeSame(403);

        // Anonymous HTTP requests are denied, not silently treated as tenant A.
        $client->restart();
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $urlA.'/'.$orderB->id().'/cancel', server: $headers);
        self::assertResponseStatusCodeSame(403);

        $manager->refresh($orderB);
        self::assertSame(Order::STATUS_OPEN, $orderB->orderStatus());
        self::assertSame(Order::FULFILLMENT_RESERVED, $orderB->fulfillmentStatus());
        self::assertSame(1, $manager->getRepository(Order::class)->count([
            'tenant' => $tenantB, 'id' => $orderB->id(),
        ]));
    }
}
'''


class PedidosAislamientoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        source = CONTROLLER.read_text(encoding="utf-8")
        for needle in (
            "use BranchApiSupport;",
            "'orders.view'",
            "'orders.create'",
            "'orders.update'",
            "'tenant' => $tenant",
            "'legalEntity' => $legalEntity",
            "'inventorySource' => $sources",
            "'id' => $id",
            "$this->requireCsrf($request)",
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, source)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI necesario para el contrato HTTP")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit instalado requerido para HTTP real")
        with tempfile.TemporaryDirectory(prefix="condor-orders-http-") as directory:
            fixture = Path(directory) / "OrderScopeSmokeTest.php"
            fixture.write_text(HTTP_SMOKE, encoding="utf-8")
            syntax = subprocess.run(
                [php, "-l", str(fixture)], capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
            result = subprocess.run(
                [str(runner), str(fixture)], cwd=ROOT, text=True,
                capture_output=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

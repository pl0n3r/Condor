#!/usr/bin/env python3
"""Condor #811 AC-01: real Symfony HTTP/Doctrine customer purchase history.

The acceptance fixture uses only synthetic tenants and an ephemeral test database.
A missing PHP runner, failed authorization, or missing branch isolation fails closed.
"""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/CustomerHistoryController.php"

PHP_TEST = r"""<?php
declare(strict_types=1);

use App\Domain\Commerce\Entity\Customer;
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

final class CustomerHistoryHttpContractTest extends WebTestCase
{
    public function testHistoryIsTenantScopedAuthorizedPaginatedAndExact(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $a = new Tenant('Historial A '.$suffix, 'historia-a-'.$suffix);
        $legalA = new LegalEntity($a, 'Historia A SAS', null, true);
        $branchA = new Branch($a, 'A', 'a-'.$suffix, $legalA, true);
        $branchHidden = new Branch($a, 'B', 'b-'.$suffix, $legalA);
        $sourceA = new InventorySource($a, $legalA, 'Fuente A', 'a-'.$suffix, InventorySource::TYPE_BRANCH, $branchA);
        $sourceHidden = new InventorySource($a, $legalA, 'Fuente B', 'b-'.$suffix, InventorySource::TYPE_BRANCH, $branchHidden);
        $customerA = new Customer($a, 'Cliente '.$suffix);
        $owner = new User('history-owner-'.$suffix.'@example.test', 'Owner A');
        $ownerMembership = new Membership($a, $owner, Membership::ROLE_OWNER);
        $viewer = new User('history-viewer-'.$suffix.'@example.test', 'Viewer A');
        $viewerMembership = new Membership($a, $viewer, 'STAFF');
        $readerRole = new Role($a, 'Lector clientes '.$suffix, ['customers.view']);
        $assignment = new BranchRoleAssignment($viewerMembership, $branchA, $readerRole);
        $denied = new User('history-denied-'.$suffix.'@example.test', 'Denied A');
        $deniedMembership = new Membership($a, $denied, 'STAFF');
        $wrongRole = new Role($a, 'Sin clientes '.$suffix, ['inventory.view']);
        $deniedAssignment = new BranchRoleAssignment($deniedMembership, $branchA, $wrongRole);

        $b = new Tenant('Historial B '.$suffix, 'historia-b-'.$suffix);
        $legalB = new LegalEntity($b, 'Historia B SAS', null, true);
        $branchB = new Branch($b, 'Ajenos', 'ajenos-'.$suffix, $legalB, true);
        $sourceB = new InventorySource($b, $legalB, 'Fuente ajena', 'ajena-'.$suffix, InventorySource::TYPE_BRANCH, $branchB);
        $customerB = new Customer($b, 'Cliente extranjero '.$suffix);
        $foreignOrder = new Order($b, $sourceB, null, $customerB, null, 'foreign-'.$suffix, 'COP', 7777);
        $hiddenOrder = new Order($a, $sourceHidden, null, $customerA, null, 'hidden-'.$suffix, 'COP', 9000);
        $cancelled = new Order($a, $sourceA, null, $customerA, null, 'cancel-'.$suffix, 'COP', 9999);
        $cancelled->cancelAndRelease();

        foreach ([
            $a, $legalA, $branchA, $branchHidden, $sourceA, $sourceHidden,
            $customerA, $owner, $ownerMembership, $viewer, $viewerMembership,
            $readerRole, $assignment, $denied, $deniedMembership, $wrongRole,
            $deniedAssignment, $b, $legalB, $branchB, $sourceB, $customerB,
            $foreignOrder, $hiddenOrder, $cancelled,
        ] as $entity) {
            $em->persist($entity);
        }
        $localOrders = [];
        for ($index = 0; $index < 21; ++$index) {
            $order = new Order(
                $a, $sourceA, null, $customerA, null,
                'local-'.$suffix.'-'.$index, 'COP', 100,
            );
            $em->persist($order);
            $localOrders[] = $order;
        }
        $em->flush();
        $url = '/api/customers/'.$customerA->id().'/history';

        $client->loginUser($owner);
        $client->request('GET', $url.'?page=1');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(23, $body['summary']['order_count']);
        self::assertSame('11100', (string) $body['summary']['total_purchased_by_currency_minor']['COP']);
        self::assertNotNull($body['summary']['last_purchase_at']);
        self::assertCount(20, $body['orders']);
        self::assertTrue($body['pagination']['has_next']);
        $pageOne = array_column($body['orders'], 'id');
        self::assertNotContains($foreignOrder->id(), $pageOne);
        $client->request('GET', $url.'?page=2');
        self::assertResponseStatusCodeSame(200);
        $pageTwo = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $pageTwo['orders']);
        self::assertFalse($pageTwo['pagination']['has_next']);
        self::assertSame([], array_values(array_intersect($pageOne, array_column($pageTwo['orders'], 'id'))));

        $client->request('GET', '/api/customers/'.$customerB->id().'/history');
        self::assertResponseStatusCodeSame(404);
        foreach (['?page=0', '?page=1001', '?page[]=1', '?tenant='.$b->id()] as $query) {
            $client->request('GET', $url.$query);
            self::assertResponseStatusCodeSame(400);
        }

        $client->loginUser($viewer);
        $client->request('GET', $url.'?page=2');
        self::assertResponseStatusCodeSame(200);
        $viewerBody = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(22, $viewerBody['summary']['order_count']);
        self::assertSame('2100', (string) $viewerBody['summary']['total_purchased_by_currency_minor']['COP']);
        self::assertNotContains($hiddenOrder->id(), array_column($viewerBody['orders'], 'id'));
        self::assertNotContains($foreignOrder->id(), array_column($viewerBody['orders'], 'id'));
        $client->request('GET', '/api/customers/'.$customerB->id().'/history');
        self::assertResponseStatusCodeSame(404);
        $client->loginUser($denied);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $client->restart();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }
}
"""


class ClienteHistorialApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI real requerido")
        for check in ("'tenant' => $tenant", "'customers.view'", "'source.branch IN (:branches)'", "no-store"):
            self.assertIn(check, CONTROLLER.read_text(encoding="utf-8"))
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit y dependencias Symfony requeridos")
        lint = subprocess.run([php, "-l", str(CONTROLLER)], cwd=ROOT, capture_output=True, text=True, timeout=20, check=False)
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        with tempfile.TemporaryDirectory(prefix="condor-customer-history-") as tmp:
            fixture = Path(tmp) / "CustomerHistoryHttpContractTest.php"
            fixture.write_text(PHP_TEST, encoding="utf-8")
            lint = subprocess.run([php, "-l", str(fixture)], cwd=ROOT, capture_output=True, text=True, timeout=20, check=False)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            run = subprocess.run([str(runner), str(fixture)], cwd=ROOT, capture_output=True, text=True, timeout=180, check=False)
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()

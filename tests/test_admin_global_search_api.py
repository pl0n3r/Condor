#!/usr/bin/env python3
"""Condor #813: acceptance against real Symfony HTTP, Doctrine and tenant ACL."""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/AdminSearchController.php"
READER = ROOT / "src/Application/Admin/GlobalSearch.php"

# The fixture lives outside version control and uses only synthetic tenants.
PHP_TEST = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
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

final class AdminGlobalSearchGeneratedTest extends WebTestCase
{
    public function testScopedSearchAndDeniedCases(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Busqueda A '.$suffix, 'busqueda-a-'.$suffix);
        $legal = new LegalEntity($tenant, 'Empresa A SAS', null, true);
        $branch = new Branch($tenant, 'Principal', 'principal-'.$suffix, $legal);
        $source = new InventorySource(
            $tenant, $legal, 'Principal', 'principal-source-'.$suffix,
            InventorySource::TYPE_BRANCH, $branch,
        );
        $restricted = new Branch($tenant, 'Privada', 'privada-'.$suffix, $legal);
        $restrictedSource = new InventorySource(
            $tenant, $legal, 'Privada', 'privada-source-'.$suffix,
            InventorySource::TYPE_BRANCH, $restricted,
        );
        $logicalSource = new InventorySource(
            $tenant, $legal, 'Virtual', 'logical-source-'.$suffix,
            InventorySource::TYPE_LOGICAL,
        );
        $owner = new User('search-owner-'.$suffix.'@example.test', 'Dueño');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $catalogUser = new User('search-catalog-'.$suffix.'@example.test', 'Catálogo');
        $catalogMembership = new Membership($tenant, $catalogUser, 'STAFF');
        $catalogRole = new Role($tenant, 'Catálogo '.$suffix, ['catalog.view']);
        $catalogAssignment = new BranchRoleAssignment($catalogMembership, $branch, $catalogRole);
        $ordersUser = new User('search-orders-'.$suffix.'@example.test', 'Pedidos');
        $ordersMembership = new Membership($tenant, $ordersUser, 'STAFF');
        $ordersRole = new Role($tenant, 'Pedidos '.$suffix, ['orders.view']);
        $ordersAssignment = new BranchRoleAssignment($ordersMembership, $branch, $ordersRole);

        $order = new Order($tenant, $source, null, null, null, 'search-a-'.$suffix, 'COP', 1200);
        $otherOrder = new Order($tenant, $restrictedSource, null, null, null, 'search-b-'.$suffix, 'COP', 2400);
        $logicalOrder = new Order(
            $tenant, $logicalSource, null, null, null, 'search-logical-'.$suffix, 'COP', 1600,
        );
        $needle = substr($order->id(), 0, 6);
        $product = new Product($tenant, 'Producto '.$needle, 'search-product-'.$suffix);
        $customer = new Customer($tenant, 'Cliente '.$needle);

        $foreign = new Tenant('Busqueda B '.$suffix, 'busqueda-b-'.$suffix);
        $foreignLegal = new LegalEntity($foreign, 'Empresa B SAS', null, true);
        $foreignBranch = new Branch($foreign, 'Principal B', 'foreign-'.$suffix, $foreignLegal);
        $foreignSource = new InventorySource(
            $foreign, $foreignLegal, 'Principal B', 'foreign-source-'.$suffix,
            InventorySource::TYPE_BRANCH, $foreignBranch,
        );
        $foreignOwner = new User('search-foreign-'.$suffix.'@example.test', 'Dueño B');
        $foreignMembership = new Membership($foreign, $foreignOwner, Membership::ROLE_OWNER);
        $foreignProduct = new Product($foreign, 'Producto '.$needle, 'foreign-product-'.$suffix);
        $foreignCustomer = new Customer($foreign, 'Cliente '.$needle);
        $foreignOrder = new Order(
            $foreign, $foreignSource, null, null, null, 'search-foreign-'.$suffix, 'COP', 7200,
        );

        foreach ([
            $tenant, $legal, $branch, $source, $restricted, $restrictedSource,
            $logicalSource, $logicalOrder,
            $owner, $ownerMembership, $catalogUser, $catalogMembership,
            $catalogRole, $catalogAssignment, $ordersUser, $ordersMembership,
            $ordersRole, $ordersAssignment, $order, $otherOrder, $product, $customer,
            $foreign, $foreignLegal, $foreignBranch, $foreignSource,
            $foreignOwner, $foreignMembership, $foreignProduct, $foreignCustomer, $foreignOrder,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $url = '/api/admin/search?q='.$needle;
        $client->loginUser($owner);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
        );
        $results = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        )['results'];
        self::assertSame(['products', 'customers', 'orders'], array_keys($results));
        self::assertContains($product->id(), array_column($results['products'], 'id'));
        self::assertContains($customer->id(), array_column($results['customers'], 'id'));
        self::assertContains($order->id(), array_column($results['orders'], 'id'));
        self::assertContains($logicalOrder->id(), array_column($results['orders'], 'id'));
        foreach (['products', 'customers', 'orders'] as $group) {
            self::assertLessThanOrEqual(5, count($results[$group]));
            foreach ($results[$group] as $item) {
                self::assertStringStartsWith('/admin#', $item['href']);
                self::assertArrayHasKey('subtitle', $item);
            }
        }
        $json = json_encode($results, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($foreignProduct->id(), $json);
        self::assertStringNotContainsString($foreignCustomer->id(), $json);
        self::assertStringNotContainsString($foreignOrder->id(), $json);

        $client->loginUser($catalogUser);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $onlyCatalog = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        )['results'];
        self::assertContains($product->id(), array_column($onlyCatalog['products'], 'id'));
        self::assertSame([], $onlyCatalog['customers']);
        self::assertSame([], $onlyCatalog['orders']);

        $client->loginUser($ordersUser);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $onlyOrders = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        )['results'];
        self::assertSame([], $onlyOrders['products']);
        self::assertSame([], $onlyOrders['customers']);
        self::assertContains($order->id(), array_column($onlyOrders['orders'], 'id'));
        self::assertNotContains($otherOrder->id(), array_column($onlyOrders['orders'], 'id'));
        self::assertNotContains($logicalOrder->id(), array_column($onlyOrders['orders'], 'id'));

        $client->loginUser($foreignOwner);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $foreignResults = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($product->id(), $foreignResults);
        self::assertStringNotContainsString($customer->id(), $foreignResults);
        self::assertStringNotContainsString($order->id(), $foreignResults);
        self::assertStringNotContainsString($logicalOrder->id(), $foreignResults);

        $client->loginUser($owner);
        foreach (['a', '%', str_repeat('z', 65), 'ab%_', '  '] as $invalid) {
            $client->request('GET', '/api/admin/search?q='.rawurlencode($invalid));
            self::assertResponseStatusCodeSame(400);
        }
        // Re-login into a fresh client session for a deterministic limit.
        $client->restart();
        $client->loginUser($owner);
        for ($i = 0; $i < 30; $i++) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(200);
        }
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(429);
        $client->restart();
        $client->request('GET', $url);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }
}
"""


class BusquedaGlobalApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required")
        for file in [CONTROLLER, READER]:
            self.assertTrue(file.is_file(), str(file))
            result = subprocess.run(
                [php, "-l", str(file)],
                cwd=ROOT, text=True, capture_output=True, check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        controller = CONTROLLER.read_text(encoding="utf-8")
        reader = READER.read_text(encoding="utf-8")
        for marker in [
            "CurrentTenantForUser", "hasAnyPermission(", "orders.view",
            "getRepository(Branch::class)", "TooManyRequestsHttpException",
            "no-store", "mb_strlen($query", "30",
        ]:
            self.assertIn(marker, controller)
        for marker in [
            "p.tenant = :tenant", "c.tenant = :tenant",
            "o.tenant = :tenant", "o.inventorySource IN (:sources)",
            "MAX_PER_TYPE = 5", "setMaxResults(self::MAX_PER_TYPE)",
        ]:
            self.assertIn(marker, reader)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Symfony/PHPUnit runner required")
        with tempfile.TemporaryDirectory(prefix="condor-global-search-") as directory:
            fixture = Path(directory) / "AdminGlobalSearchGeneratedTest.php"
            fixture.write_text(PHP_TEST, encoding="utf-8")
            run = subprocess.run(
                [str(runner), "--filter", "testScopedSearchAndDeniedCases", str(fixture)],
                cwd=ROOT, text=True, capture_output=True, check=False, timeout=180,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()

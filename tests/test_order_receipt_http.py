#!/usr/bin/env python3
"""Condor #810: executable synthetic HTTP receipt contract, without real PII."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/OrderReceiptController.php"
TEMPLATE = ROOT / "templates/orders/receipt.html.twig"

# Kept in this claimed file: PHPUnit runs this ephemeral fixture with the
# repository's own bootstrap and deletes it immediately after execution.
HTTP_TEST = r'''<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderReceiptSyntheticHttpTest extends WebTestCase
{
    public function testReceiptTotalsTenantAndPermissions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(6));

        $tenantA = new Tenant('Comercio A '.$suffix, 'receipt-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Empresa recibos A', null, true);
        $branchA = new Branch($tenantA, 'Sede principal A', 'receipt-'.$suffix, $legalA, true);
        $sourceA = new InventorySource(
            $tenantA, $legalA, 'Inventario A', 'receipt-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $priceA = new PriceList($tenantA, 'Lista A', 'receipt-'.$suffix);
        $product = new Product($tenantA, 'Producto de prueba', 'producto-'.$suffix);
        $variant = new ProductVariant($tenantA, $product, 'REC'.strtoupper($suffix), 'Variante A');
        $customer = new Customer($tenantA, 'Cliente sintético A');
        $orderA = new Order($tenantA, $sourceA, null, $customer, null, 'receipt-a-'.$suffix, 'COP', 3000);
        $line = new OrderLine($orderA, $variant, $priceA, 2, 1500, 1500, 'COP', null);
        $ownerA = new User('receipt-owner-'.$suffix.'@example.test', 'Owner A');
        $membershipA = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);
        $staff = new User('receipt-staff-'.$suffix.'@example.test', 'Staff A');
        $staffMembership = new Membership($tenantA, $staff, 'STAFF');

        $tenantB = new Tenant('Comercio B '.$suffix, 'receipt-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Empresa recibos B', null, true);
        $branchB = new Branch($tenantB, 'Sede B', 'receipt-'.$suffix, $legalB, true);
        $sourceB = new InventorySource(
            $tenantB, $legalB, 'Inventario B', 'receipt-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );
        $orderB = new Order($tenantB, $sourceB, null, null, null, 'receipt-b-'.$suffix, 'COP', 0);
        $ownerB = new User('receipt-owner-b-'.$suffix.'@example.test', 'Owner B');
        $membershipB = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);
        foreach ([
            $tenantA, $legalA, $branchA, $sourceA, $priceA, $product, $variant,
            $customer, $orderA, $line, $ownerA, $membershipA, $staff, $staffMembership,
            $tenantB, $legalB, $branchB, $sourceB, $orderB, $ownerB, $membershipB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $urlA = '/api/orders/'.$orderA->id().'/receipt';
        $urlB = '/api/orders/'.$orderB->id().'/receipt';
        $client->loginUser($ownerA);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Producto de prueba', $html);
        self::assertStringContainsString('Cliente sintético A', $html);
        self::assertStringContainsString('30,00', $html);
        self::assertStringContainsString('Fecha (Colombia)', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString("style-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));

        $client->request('GET', $urlB);
        self::assertResponseStatusCodeSame(404);
        $client->loginUser($ownerB);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(404);
        $client->loginUser($staff);
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(403);
        $client->restart();
        $client->request('GET', $urlA);
        self::assertResponseStatusCodeSame(401);
    }
}
'''


class ComprobantePedidoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        self.assertTrue(TEMPLATE.is_file(), str(TEMPLATE))
        controller = CONTROLLER.read_text(encoding="utf-8")
        template = TEMPLATE.read_text(encoding="utf-8")
        for marker in (
            "'/api/orders/{id}/receipt'",
            "'tenant' => $tenant",
            "'orders.view'",
            "'order' => $order",
            "America/Bogota",
            "totalAmountMinor()",
            "lineTotalMinor()",
            "no-store",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, controller)
        for banned in ('<script', '<style', 'style=', 'onclick='):
            self.assertNotIn(banned, template.lower())

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es requerido")
        for source in (CONTROLLER,):
            checked = subprocess.run(
                [php, "-l", str(source)], capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)

        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit instalado requerido para HTTP real")
        with tempfile.TemporaryDirectory(prefix="condor-receipt-") as dirname:
            target = Path(dirname) / "OrderReceiptSyntheticHttpTest.php"
            target.write_text(HTTP_TEST, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(target)], capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(runner), "--configuration", str(ROOT / "phpunit.xml.dist"), str(target)],
                cwd=ROOT, text=True, capture_output=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

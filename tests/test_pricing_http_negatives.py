#!/usr/bin/env python3
"""Condor #755: pricing HTTP negative contract with two synthetic tenants.

Execute the actual Symfony PricingController through WebTestCase and a disposable
PHPUnit fixture. No production network, customer records, or schema changes.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PricingController.php"

HTTP_NEGATIVES = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PricingHttpNegativesSmokeTest extends WebTestCase
{
    public function testInvalidPricesCurrenciesAndForeignListsPreservePersistedData(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));

        $tenantA = new Tenant('Precios A '.$suffix, 'pricing-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Precios A SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal', 'principal-'.$suffix, $legalA, true);
        $userA = new User('pricing-owner-'.$suffix.'@example.test', 'Pricing owner');
        $membershipA = new Membership($tenantA, $userA, Membership::ROLE_OWNER);
        $productA = new Product($tenantA, 'Producto A', 'producto-a-'.$suffix);
        $variantA = new ProductVariant($tenantA, $productA, 'PRICE-A-'.$suffix, 'Principal');
        $listA = new PriceList($tenantA, 'Lista COP A', 'lista-cop-a-'.$suffix, 'COP');
        $savedA = new VariantPrice($tenantA, $listA, $variantA, 4500);

        $tenantB = new Tenant('Precios B '.$suffix, 'pricing-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Precios B SAS', null, true);
        $branchB = new Branch($tenantB, 'Ajena', 'ajena-'.$suffix, $legalB, true);
        $productB = new Product($tenantB, 'Producto B', 'producto-b-'.$suffix);
        $variantB = new ProductVariant($tenantB, $productB, 'PRICE-B-'.$suffix, 'Ajena');
        $listB = new PriceList($tenantB, 'LISTA_EXTERNA_'.$suffix, 'lista-cop-b-'.$suffix, 'COP');
        $savedB = new VariantPrice($tenantB, $listB, $variantB, 9900);

        foreach ([
            $tenantA, $legalA, $branchA, $userA, $membershipA,
            $productA, $variantA, $listA, $savedA,
            $tenantB, $legalB, $branchB, $productB, $variantB, $listB, $savedB,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($userA);
        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];
        $base = '/api/v1/branches/'.$branchA->id().'/pricing';
        $pricePath = $base.'/lists/'.$listA->id().'/variants/'.$variantA->id();

        $assertUnchanged = static function () use (
            $manager, $tenantA, $tenantB, $savedA, $savedB, $listA, $listB
        ): void {
            $manager->refresh($savedA);
            $manager->refresh($savedB);
            self::assertSame(4500, $savedA->amountMinor());
            self::assertSame(9900, $savedB->amountMinor());
            self::assertSame(1, $manager->getRepository(PriceList::class)->count(['tenant' => $tenantA]));
            self::assertSame(1, $manager->getRepository(PriceList::class)->count(['tenant' => $tenantB]));
            self::assertSame('COP', $listA->currency());
            self::assertSame('COP', $listB->currency());
            self::assertSame(1, $manager->getRepository(VariantPrice::class)->count(['tenant' => $tenantA]));
            self::assertSame(1, $manager->getRepository(VariantPrice::class)->count(['tenant' => $tenantB]));
        };
        $assertUnchanged();

        // Currency rejection happens on the real HTTP POST, before persistence.
        $client->jsonRequest('POST', $base.'/lists', [
            'name' => 'No permitida', 'slug' => 'usd-'.$suffix, 'currency' => 'USD',
        ], $headers);
        self::assertResponseStatusCodeSame(422);
        $assertUnchanged();

        // Amounts must be non-negative integers; rejected PUTs cannot mutate
        // the existing persisted variant price or create new tenant rows.
        foreach ([-1, -500, 'not-an-integer'] as $amount) {
            $client->jsonRequest('PUT', $pricePath, ['amount_minor' => $amount], $headers);
            self::assertResponseStatusCodeSame(422);
            $assertUnchanged();
        }

        // An authenticated A owner may not reference B's price list or variant,
        // even through a valid local branch and an otherwise valid amount.
        foreach ([
            $base.'/lists/'.$listB->id().'/variants/'.$variantA->id(),
            $base.'/lists/'.$listA->id().'/variants/'.$variantB->id(),
        ] as $foreignPath) {
            $client->jsonRequest('PUT', $foreignPath, ['amount_minor' => 7000], $headers);
            self::assertResponseStatusCodeSame(404);
            $assertUnchanged();
        }

        $client->request('GET', $base.'/effective?variant='.$variantB->id());
        self::assertResponseStatusCodeSame(404);
        $assertUnchanged();
        $client->request(
            'GET', $base.'/effective?variant='.$variantA->id().'&price_list='.$listB->id(),
        );
        self::assertResponseStatusCodeSame(404);
        $assertUnchanged();
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/pricing');
        self::assertResponseStatusCodeSame(404);
        $assertUnchanged();

        // Deny mutation without the same authentic admin CSRF token.
        $client->jsonRequest('PUT', $pricePath, ['amount_minor' => 7000]);
        self::assertResponseStatusCodeSame(403);
        $assertUnchanged();

        // A positive control proves we are exercising a functional endpoint,
        // rather than treating universal denials as a passing HTTP contract.
        $client->jsonRequest('PUT', $pricePath, ['amount_minor' => 4600], $headers);
        self::assertResponseStatusCodeSame(200);
        $manager->refresh($savedA);
        $manager->refresh($savedB);
        self::assertSame(4600, $savedA->amountMinor());
        self::assertSame(9900, $savedB->amountMinor());
        self::assertSame(1, $manager->getRepository(VariantPrice::class)->count(['tenant' => $tenantA]));

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$listA->id()], array_column($payload['price_lists'], 'id'));
        self::assertSame([$savedA->id()], array_column($payload['variant_prices'], 'id'));
        self::assertStringNotContainsString('LISTA_EXTERNA_', (string) $client->getResponse()->getContent());
    }
}
"""


class PreciosNegativasHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio para el contrato HTTP")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit real es obligatorio para el contrato HTTP")

        with tempfile.TemporaryDirectory(prefix="condor-pricing-http-") as tmp:
            fixture = Path(tmp) / "PricingHttpNegativesSmokeTest.php"
            fixture.write_text(HTTP_NEGATIVES, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            completed = subprocess.run(
                [str(runner), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
            self.assertRegex(completed.stdout + completed.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

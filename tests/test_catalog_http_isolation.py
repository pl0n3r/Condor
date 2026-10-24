#!/usr/bin/env python3
"""Condor #754: real Symfony HTTP contract for cross-tenant catalog isolation.

A temporary PHPUnit WebTestCase exercises the router, authentication, Doctrine,
authorization and CSRF using only synthetic tenants and products.
"""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/CatalogController.php"

PHP_HTTP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogHttpTenantIsolationSmokeTest extends WebTestCase
{
    public function testTenantCannotReadCreateOrEditForeignCatalog(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(5));

        $tenantA = new Tenant('Catalogo A '.$suffix, 'catalog-a-'.$suffix);
        $tenantB = new Tenant('Catalogo B '.$suffix, 'catalog-b-'.$suffix);
        $branchA = new Branch($tenantA, 'Principal A', 'main-a-'.$suffix, null, true);
        $branchB = new Branch($tenantB, 'Principal B', 'main-b-'.$suffix, null, true);
        $owner = new User('catalog-owner-'.$suffix.'@example.test', 'Owner A');
        $ownerMembership = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $viewer = new User('catalog-viewer-'.$suffix.'@example.test', 'Viewer A');
        $viewerMembership = new Membership($tenantA, $viewer, 'ADMIN');
        $viewRole = new Role($tenantA, 'Solo lectura catalogo', ['catalog.view']);
        $viewAssignment = new BranchRoleAssignment($viewerMembership, $branchA, $viewRole);
        $productA = new Product($tenantA, 'Producto propio '.$suffix, 'own-'.$suffix);
        $variantA = new ProductVariant($tenantA, $productA, 'OWN-'.$suffix, 'Propia');
        $productB = new Product($tenantB, 'Producto ajeno '.$suffix, 'foreign-'.$suffix);
        $variantB = new ProductVariant($tenantB, $productB, 'FOREIGN-'.$suffix, 'Ajena');
        foreach ([
            $tenantA, $tenantB, $branchA, $branchB, $owner, $ownerMembership,
            $viewer, $viewerMembership, $viewRole, $viewAssignment,
            $productA, $variantA, $productB, $variantB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];
        $ownBase = '/api/v1/branches/'.$branchA->id().'/catalog/products';
        $foreignBase = '/api/v1/branches/'.$branchB->id().'/catalog/products';

        // A successful read must omit BOTH foreign products and variants.
        $client->request('GET', $ownBase);
        self::assertResponseStatusCodeSame(200);
        $catalog = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        );
        self::assertCount(1, $catalog['products']);
        self::assertSame($productA->id(), $catalog['products'][0]['id']);
        self::assertSame($variantA->id(), $catalog['products'][0]['variants'][0]['id']);
        self::assertStringNotContainsString(
            $productB->id(), (string) $client->getResponse()->getContent()
        );
        self::assertStringNotContainsString(
            $variantB->sku(), (string) $client->getResponse()->getContent()
        );
        $client->request('GET', $foreignBase);
        self::assertResponseStatusCodeSame(404);

        // Foreign IDs cannot be used to create or modify records.
        $client->jsonRequest('PATCH', $ownBase.'/'.$productB->id(), [
            'name' => 'Intrusion producto',
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $ownBase.'/'.$productB->id().'/variants', [
            'sku' => 'X-'.$suffix, 'name' => 'Intrusion variante',
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest(
            'PATCH',
            $ownBase.'/'.$productA->id().'/variants/'.$variantB->id(),
            ['name' => 'Intrusion variante'],
            $headers,
        );
        self::assertResponseStatusCodeSame(404);
        // Cross-tenant soft deletion is also forbidden and must not mutate flags.
        $client->request('DELETE', $ownBase.'/'.$productB->id(), [], [], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->request(
            'DELETE',
            $ownBase.'/'.$productA->id().'/variants/'.$variantB->id(),
            [], [], $headers,
        );
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $foreignBase, [
            'name' => 'Intrusion sede', 'slug' => 'intrusion-'.$suffix,
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $em->refresh($productB);
        $em->refresh($variantB);
        self::assertSame('Producto ajeno '.$suffix, $productB->name());
        self::assertSame('Ajena', $variantB->name());
        self::assertTrue($productB->isActive());
        self::assertTrue($variantB->isActive());
        self::assertNull($em->getRepository(Product::class)->findOneBy([
            'tenant' => $tenantB, 'slug' => 'intrusion-'.$suffix,
        ]));
        self::assertNull($em->getRepository(ProductVariant::class)->findOneBy([
            'tenant' => $tenantA, 'sku' => 'X-'.$suffix,
        ]));

        // Positive controls prove actual HTTP routing and persisted writes.
        $client->jsonRequest('POST', $ownBase, [
            'name' => 'Creado local', 'slug' => 'created-'.$suffix,
        ], $headers);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        )['product']['id'];
        $client->jsonRequest('PATCH', $ownBase.'/'.$created, [
            'name' => 'Actualizado local',
        ], $headers);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('POST', $ownBase.'/'.$created.'/variants', [
            'name' => 'Nueva variante', 'sku' => 'LOCAL-'.$suffix,
        ], $headers);
        self::assertResponseStatusCodeSame(201);
        $newVariant = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR
        )['variant']['id'];
        $client->jsonRequest('PATCH', $ownBase.'/'.$created.'/variants/'.$newVariant, [
            'name' => 'Variante actualizada',
        ], $headers);
        self::assertResponseStatusCodeSame(200);
        $createdProduct = $em->getRepository(Product::class)->find($created);
        $createdVariant = $em->getRepository(ProductVariant::class)->find($newVariant);
        self::assertSame($tenantA->id(), $createdProduct->tenant()->id());
        self::assertSame('Actualizado local', $createdProduct->name());
        self::assertSame('Variante actualizada', $createdVariant->name());

        // A delegated catalog viewer can read but cannot create or update.
        $client->loginUser($viewer);
        $client->request('GET', $ownBase);
        self::assertResponseStatusCodeSame(200);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $viewerCsrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($viewerCsrf);
        self::assertNotSame('', $viewerCsrf);
        $viewerHeaders = ['HTTP_X_CSRF_TOKEN' => $viewerCsrf];
        $client->jsonRequest('POST', $ownBase, [
            'name' => 'Sin permisos', 'slug' => 'denied-'.$suffix,
        ], $viewerHeaders);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PATCH', $ownBase.'/'.$created, [
            'name' => 'Cambio denegado',
        ], $viewerHeaders);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $ownBase.'/'.$created.'/variants', [
            'name' => 'Sin permiso', 'sku' => 'DENIED-'.$suffix,
        ], $viewerHeaders);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PATCH', $ownBase.'/'.$created.'/variants/'.$newVariant, [
            'name' => 'Cambio variante denegado',
        ], $viewerHeaders);
        self::assertResponseStatusCodeSame(403);
        // Read-only permission must also prohibit soft-deleting own records.
        $client->request('DELETE', $ownBase.'/'.$created, [], [], $viewerHeaders);
        self::assertResponseStatusCodeSame(403);
        $client->request(
            'DELETE', $ownBase.'/'.$created.'/variants/'.$newVariant,
            [], [], $viewerHeaders,
        );
        self::assertResponseStatusCodeSame(403);
        $em->refresh($createdProduct);
        $em->refresh($createdVariant);
        self::assertTrue($createdProduct->isActive());
        self::assertTrue($createdVariant->isActive());
        self::assertSame('Actualizado local', $createdProduct->name());
        self::assertSame('Variante actualizada', $createdVariant->name());
        self::assertNull($em->getRepository(Product::class)->findOneBy([
            'tenant' => $tenantA, 'slug' => 'denied-'.$suffix,
        ]));
        self::assertNull($em->getRepository(ProductVariant::class)->findOneBy([
            'tenant' => $tenantA, 'sku' => 'DENIED-'.$suffix,
        ]));

        // A missing CSRF token is a separate denial.
        $client->loginUser($owner);
        $client->jsonRequest('POST', $ownBase, [
            'name' => 'Sin CSRF', 'slug' => 'no-csrf-'.$suffix,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($em->getRepository(Product::class)->findOneBy([
            'tenant' => $tenantA, 'slug' => 'no-csrf-'.$suffix,
        ]));
    }
}
"""


class CatalogoAislamientoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI obligatorio para contrato HTTP")
        phpunit = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(phpunit.is_file(), "PHPUnit real obligatorio para contrato HTTP")

        with tempfile.TemporaryDirectory(prefix="condor-catalog-http-") as directory:
            fixture = Path(directory) / "CatalogHttpTenantIsolationSmokeTest.php"
            fixture.write_text(PHP_HTTP_SCENARIO, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(phpunit), str(fixture)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=180,
            )
            # Publish real PHPUnit evidence when AC-01 is executed in an authorized runner.
            if result.stdout or result.stderr:
                print(result.stdout + result.stderr, end="")
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertRegex(result.stdout + result.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Condor #808: pruebas reales HTTP de importación CSV con fixtures sintéticos."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IMPORTER = ROOT / "src/Application/Catalog/ProductCsvImporter.php"
CONTROLLER = ROOT / "src/Http/Controller/CatalogImportController.php"

PHP_FIXTURE = r'''<?php

declare(strict_types=1);

namespace App\Tests\Http;

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

final class CatalogImportSyntheticHttpTest extends WebTestCase
{
    public function testPreviewCommitIdempotencyAndTenantIsolation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(6));

        $tenantA = new Tenant('Catálogo A '.$suffix, 'import-a-'.$suffix);
        $branchA = new Branch($tenantA, 'Sede A', 'import-'.$suffix, null, true);
        $ownerA = new User('import-a-'.$suffix.'@example.test', 'Owner A');
        $memberA = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);

        $tenantB = new Tenant('Catálogo B '.$suffix, 'import-b-'.$suffix);
        $branchB = new Branch($tenantB, 'Sede B', 'import-'.$suffix, null, true);
        $ownerB = new User('import-b-'.$suffix.'@example.test', 'Owner B');
        $memberB = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);

        $viewer = new User('import-view-'.$suffix.'@example.test', 'Viewer');
        $viewerMember = new Membership($tenantA, $viewer, 'ADMIN');
        $viewerRole = new Role($tenantA, 'Solo ver catálogo', ['catalog.view']);
        $viewerAccess = new BranchRoleAssignment($viewerMember, $branchA, $viewerRole);

        foreach ([
            $tenantA, $branchA, $ownerA, $memberA,
            $tenantB, $branchB, $ownerB, $memberB,
            $viewer, $viewerMember, $viewerRole, $viewerAccess,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $csv = "sku,product_name,product_slug,variant_name\n"
            ."SKU-1,Camiseta,cami,Negra\n"
            ."sku-2,Camiseta,cami,Blanca\n"
            ."SKU-BAD,,,\n";
        $payload = ['branch_id' => $branchA->id(), 'csv' => $csv];
        $client->loginUser($ownerA);
        $csrf = $this->csrf($client);

        $client->jsonRequest('POST', '/api/catalog/import/preview', $payload, [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        $preview = $this->json($client);
        self::assertSame(3, $preview['total']);
        self::assertSame(2, $preview['valid']);
        self::assertSame(1, $preview['errors']);
        self::assertSame('error', $preview['rows'][2]['status']);
        self::assertNull($em->getRepository(Product::class)->findOneBy([
            'tenant' => $tenantA, 'slug' => 'cami',
        ]));

        $client->jsonRequest('POST', '/api/catalog/import/commit', $payload, [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        $first = $this->json($client);
        self::assertSame(2, $first['created']);
        self::assertSame(1, $first['errors']);
        self::assertInstanceOf(Product::class, $em->getRepository(Product::class)->findOneBy([
            'tenant' => $tenantA, 'slug' => 'cami',
        ]));
        self::assertCount(2, $em->getRepository(ProductVariant::class)->findBy([
            'tenant' => $tenantA, 'active' => true,
        ]));

        $client->jsonRequest('POST', '/api/catalog/import/commit', $payload, [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        $again = $this->json($client);
        self::assertSame(0, $again['created']);
        self::assertSame(2, $again['existing']);
        self::assertCount(2, $em->getRepository(ProductVariant::class)->findBy([
            'tenant' => $tenantA, 'active' => true,
        ]));

        $client->jsonRequest('POST', '/api/catalog/import/commit', [
            'branch_id' => $branchB->id(), 'csv' => $csv,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $em->getRepository(ProductVariant::class)->findBy([
            'tenant' => $tenantB,
        ]));

        $client->jsonRequest('POST', '/api/catalog/import/commit', [
            'branch_id' => $branchA->id(), 'csv' => $csv,
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($viewer);
        $client->jsonRequest('POST', '/api/catalog/import/preview', $payload, [
            'HTTP_X_CSRF_TOKEN' => $this->csrf($client),
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($ownerB);
        $client->jsonRequest('POST', '/api/catalog/import/preview', $payload, [
            'HTTP_X_CSRF_TOKEN' => $this->csrf($client),
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    private function csrf($client): string
    {
        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $value = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($value);
        return $value;
    }

    /** @return array<string, mixed> */
    private function json($client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }
}
'''


class CatalogoImportCsvApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(IMPORTER.is_file(), str(IMPORTER))
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        importer = IMPORTER.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        for marker in (
            "'/api/catalog/import/preview'",
            "'/api/catalog/import/commit'",
            "'catalog.create'",
            "authorizedBranchScope",
            "requireCsrf",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, controller)
        for marker in (
            "wrapInTransaction",
            "'tenant' => $tenant",
            "'sku' => $sku",
            "'status' => 'error'",
            "ProductVariant(",
            "MAX_ROWS = 500",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, importer)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido")
        for source in (IMPORTER, CONTROLLER):
            lint = subprocess.run([php, "-l", str(source)], capture_output=True, text=True)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit requerido para aceptación HTTP real")
        with tempfile.TemporaryDirectory(prefix="condor-csv-") as folder:
            fixture = Path(folder) / "CatalogImportSyntheticHttpTest.php"
            fixture.write_text(PHP_FIXTURE, encoding="utf-8")
            lint = subprocess.run([php, "-l", str(fixture)], capture_output=True, text=True)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            test = subprocess.run(
                [str(runner), "--configuration", str(ROOT / "phpunit.xml.dist"), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, timeout=180,
            )
            self.assertEqual(0, test.returncode, test.stdout + test.stderr)


if __name__ == "__main__":
    unittest.main()

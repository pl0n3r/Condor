#!/usr/bin/env python3
"""#720 AC-01: authentic tenant-scoped HTTP scan and print-only label smoke."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/ScanController.php"

# A real Symfony HTTP client with synthetic tenants, no network or customer data.
HTTP_SMOKE = r"""<?php
declare(strict_types=1);

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScanScopeHttpSmokeTest extends WebTestCase
{
    public function testScanIsTenantScopedAndLabelIsPrintable(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));

        $alpha = new Tenant('Escaneo A '.$suffix, 'escaneo-a-'.$suffix);
        $legalA = new LegalEntity($alpha, 'Escaneo A SAS', null, true);
        $branchA = new Branch($alpha, 'Principal', 'principal-'.$suffix, $legalA, true);
        $owner = new User('scan-'.$suffix.'@example.test', 'Owner');
        $membership = new Membership($alpha, $owner, Membership::ROLE_OWNER);
        $productA = new Product($alpha, 'Producto A', 'producto-a-'.$suffix);
        $variantA = new ProductVariant(
            $alpha, $productA, 'SCAN-0001', 'Café & <script>malicioso</script>',
        );
        $inactiveProduct = new Product($alpha, 'Inactivo', 'inactivo-'.$suffix);
        $inactiveVariant = new ProductVariant($alpha, $inactiveProduct, 'STOP-0001', 'Oculto');
        $inactiveProduct->deactivate();

        $beta = new Tenant('Escaneo B '.$suffix, 'escaneo-b-'.$suffix);
        $legalB = new LegalEntity($beta, 'Escaneo B SAS', null, true);
        $branchB = new Branch($beta, 'Principal', 'principal-'.$suffix, $legalB, true);
        $productB = new Product($beta, 'Producto B', 'producto-b-'.$suffix);
        $sameCodeB = new ProductVariant($beta, $productB, 'SCAN-0001', 'Ajena');
        $foreignOnly = new ProductVariant($beta, $productB, 'FOREIGN-0001', 'No visible');

        foreach ([
            $alpha, $legalA, $branchA, $owner, $membership, $productA,
            $variantA, $inactiveProduct, $inactiveVariant,
            $beta, $legalB, $branchB, $productB, $sameCodeB, $foreignOnly,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($owner);
        $base = '/api/v1/branches/'.$branchA->id().'/inventory';
        $client->request('GET', $base.'/scan/scan-0001');
        self::assertResponseIsSuccessful();
        $json = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertSame($variantA->id(), $json['variant']['id']);
        self::assertNotSame($sameCodeB->id(), $json['variant']['id']);
        self::assertSame('SCAN-0001', $json['code']);
        self::assertSame('view_variant', $json['action']);

        $client->request('GET', $base.'/scan/FOREIGN-0001');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $base.'/scan/STOP-0001');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $base.'/scan/%3Cscript%3E');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/inventory/scan/SCAN-0001');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', $base.'/labels/scan-0001');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-scan-code="SCAN-0001"', $html);
        self::assertStringContainsString(
            'Café &amp; &lt;script&gt;malicioso&lt;/script&gt;', $html,
        );
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('@page{size:60mm 40mm;margin:0}', $html);
        self::assertStringContainsString(
            'no-store', (string) $client->getResponse()->headers->get('Cache-Control'),
        );
        $client->request('GET', $base.'/labels/FOREIGN-0001');
        self::assertResponseStatusCodeSame(404);

        $client->restart();
        $client->request('GET', $base.'/scan/SCAN-0001');
        self::assertResponseStatusCodeSame(403);
    }
}
"""


class EscaneoApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        content = CONTROLLER.read_text(encoding="utf-8")
        for required in (
            "use BranchApiSupport;",
            "$this->authorizedBranchScope($branchId, 'inventory.view')",
            "ScanIdentifier::canonicalCode($code)",
            "'tenant' => $tenant",
            "'active' => true",
            "new ScanResolver()",
            "$resolver->resolve($tenant, $canonical)",
            "$this->labels->render(",
            "LabelTemplate::standard()",
        ):
            with self.subTest(required=required):
                self.assertIn(required, content)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para probar HTTP")
        lint = subprocess.run(
            [php, "-l", str(CONTROLLER)],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "PHPUnit necesario para comprobar HTTP real")
        with tempfile.TemporaryDirectory(prefix="condor-scan-http-") as directory:
            fixture = Path(directory) / "ScanScopeHttpSmokeTest.php"
            fixture.write_text(HTTP_SMOKE, encoding="utf-8")
            fixture_lint = subprocess.run(
                [php, "-l", str(fixture)],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(
                0, fixture_lint.returncode,
                fixture_lint.stdout + fixture_lint.stderr,
            )
            result = subprocess.run(
                [str(runner), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False,
                timeout=120,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""#1555: cuatro controladores HTTP reales, tenants A/B y permisos en MariaDB.

Se ejecuta durante "Pruebas de contrato e integración", ANTES del paso Composer
del workflow: prepara sólo el esquema MariaDB descartable de test, sin doubles
de controladores, sin datos personales reales y sin aceptar DB remota.
"""
from __future__ import annotations

import os
import re
import secrets
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"
CONTROLLERS = (
    ROOT / "src/Http/Controller/OrderController.php",
    ROOT / "src/Http/Controller/CustomerController.php",
    ROOT / "src/Http/Controller/InventoryController.php",
    ROOT / "src/Http/Controller/PurchasingController.php",
)

# PHPUnit ejecuta el kernel real contra Doctrine y MariaDB; el único stub es
# la identidad comercial sintética creada por las entidades de producción.
HTTP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Application\Inventory\InventoryService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReadmeControllersHttpSmokeTest extends WebTestCase
{
    public function testFourControllersRejectForeignTenantsAndUnauthorizedWrites(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(6)));

        $tenantA = new Tenant('README Empresa A '.$suffix, 'readme-a-'.$suffix);
        $tenantB = new Tenant('README Empresa B '.$suffix, 'readme-b-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'README A SAS', null, true);
        $legalB = new LegalEntity($tenantB, 'README B SAS', null, true);
        $branchA = new Branch($tenantA, 'Principal A', 'readme-principal-a-'.$suffix, $legalA, true);
        $branchB = new Branch($tenantB, 'Principal B', 'readme-principal-b-'.$suffix, $legalB, true);
        $ownerA = new User('readme-owner-a-'.$suffix.'@example.test', 'Owner A');
        $ownerB = new User('readme-owner-b-'.$suffix.'@example.test', 'Owner B');
        $viewer = new User('readme-viewer-'.$suffix.'@example.test', 'Viewer A');
        $ownerMembershipA = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);
        $ownerMembershipB = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);
        $viewerMembership = new Membership($tenantA, $viewer, 'ADMIN');
        $viewRole = new Role($tenantA, 'README lectura '.$suffix, [
            'orders.view', 'customers.view', 'inventory.view',
        ]);
        $viewAssignment = new BranchRoleAssignment($viewerMembership, $branchA, $viewRole);
        $sourceA = new InventorySource(
            $tenantA, $legalA, 'Fuente A', 'readme-fuente-a-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $sourceB = new InventorySource(
            $tenantB, $legalB, 'Fuente B '.$suffix, 'readme-fuente-b-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );
        $productA = new Product($tenantA, 'Articulo A', 'readme-articulo-a-'.$suffix);
        $productB = new Product($tenantB, 'Articulo B', 'readme-articulo-b-'.$suffix);
        $variantA = new ProductVariant($tenantA, $productA, 'README-A-'.$suffix, 'Variante A');
        $variantB = new ProductVariant($tenantB, $productB, 'README-B-'.$suffix, 'Variante B');
        $priceList = new PriceList($tenantA, 'Precios A', 'readme-precios-a-'.$suffix);
        $price = new VariantPrice($tenantA, $priceList, $variantA, 1000);
        $channel = new SalesChannel(
            $tenantA, 'Canal A', 'readme-canal-a-'.$suffix, $sourceA, $priceList,
        );
        $customerB = new Customer($tenantB, 'Cliente B '.$suffix);

        foreach ([
            $tenantA, $tenantB, $legalA, $legalB, $branchA, $branchB,
            $ownerA, $ownerB, $viewer, $ownerMembershipA, $ownerMembershipB,
            $viewerMembership, $viewRole, $viewAssignment, $sourceA, $sourceB,
            $productA, $productB, $variantA, $variantB, $priceList,
            $price, $channel, $customerB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        (new InventoryService($em))->adjust(
            $tenantA, $sourceA, $variantA, 5, $ownerA->id(),
            'readme-stock-'.$suffix,
        );

        $prefixA = '/api/v1/branches/'.$branchA->id();
        $prefixB = '/api/v1/branches/'.$branchB->id();
        $paths = [
            'orders' => '/orders',
            'customers' => '/customers',
            'inventory' => '/inventory',
            'purchasing' => '/purchasing/suppliers',
        ];
        $writes = [
            'orders' => '/orders',
            'customers' => '/customers',
            'inventory' => '/inventory/sources',
            'purchasing' => '/purchasing/suppliers',
        ];
        $payloads = [
            'orders' => [
                'inventory_source_id' => $sourceA->id(),
                'price_list_id' => $priceList->id(),
                'sales_channel_id' => $channel->id(),
                'customer_id' => null,
                'idempotency_key' => 'readme-order-'.$suffix,
                'items' => [['variant_id' => $variantA->id(), 'quantity' => 1]],
            ],
            'customers' => [
                'name' => 'Cliente A '.$suffix,
                'email' => 'readme-customer-'.$suffix.'@example.test',
            ],
            'inventory' => [
                'name' => 'Bodega logica A '.$suffix,
                'slug' => 'readme-bodega-'.$suffix,
                'type' => InventorySource::TYPE_LOGICAL,
            ],
            'purchasing' => [
                'code' => 'README-'.$suffix,
                'name' => 'Proveedor A '.$suffix,
            ],
        ];
        $client->loginUser($ownerA);
        $csrf = self::csrf($client);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        // Cada endpoint GET/POST opera realmente con el owner A.
        foreach ($paths as $name => $path) {
            $client->request('GET', $prefixA.$path);
            self::assertResponseStatusCodeSame(200, $name.' GET A');
        }
        $client->request('GET', $prefixA.'/customers');
        self::assertStringNotContainsString(
            'Cliente B '.$suffix, (string) $client->getResponse()->getContent(),
        );
        $client->request('GET', $prefixA.'/inventory');
        self::assertStringNotContainsString(
            'Fuente B '.$suffix, (string) $client->getResponse()->getContent(),
        );
        self::assertStringNotContainsString(
            'README-B-'.$suffix, (string) $client->getResponse()->getContent(),
        );
        $client->request('GET', $prefixA.'/orders');
        self::assertStringNotContainsString(
            'README-B-'.$suffix, (string) $client->getResponse()->getContent(),
        );

        foreach ($writes as $name => $path) {
            $client->jsonRequest('POST', $prefixA.$path, $payloads[$name], $headers);
            self::assertResponseStatusCodeSame(201, $name.' POST A');
        }
        $client->request('GET', $prefixA.'/purchasing/suppliers');
        self::assertStringContainsString(
            'Proveedor A '.$suffix, (string) $client->getResponse()->getContent(),
        );
        $client->request('GET', $prefixA.'/customers');
        self::assertStringContainsString(
            'Cliente A '.$suffix, (string) $client->getResponse()->getContent(),
        );

        // Un owner de B no puede consultar o escribir en sedes de A, y viceversa.
        foreach ($paths as $name => $path) {
            $client->request('GET', $prefixB.$path);
            self::assertResponseStatusCodeSame(404, $name.' GET B como A');
        }
        foreach ($writes as $name => $path) {
            $client->jsonRequest('POST', $prefixB.$path, $payloads[$name], $headers);
            self::assertResponseStatusCodeSame(404, $name.' POST B como A');
        }
        $client->loginUser($ownerB);
        foreach ($paths as $name => $path) {
            $client->request('GET', $prefixB.$path);
            self::assertResponseStatusCodeSame(200, $name.' GET B');
            $client->request('GET', $prefixA.$path);
            self::assertResponseStatusCodeSame(404, $name.' GET A como B');
        }

        // Un rol de lectura ve los recursos de su sede, pero no escribe.
        $client->loginUser($viewer);
        $viewerHeaders = ['HTTP_X_CSRF_TOKEN' => self::csrf($client)];
        foreach ($paths as $name => $path) {
            $client->request('GET', $prefixA.$path);
            self::assertResponseStatusCodeSame(200, $name.' GET viewer');
        }
        foreach ($writes as $name => $path) {
            $client->jsonRequest(
                'POST', $prefixA.$path, $payloads[$name], $viewerHeaders,
            );
            self::assertResponseStatusCodeSame(403, $name.' POST viewer');
        }

        // La ruta no protege sólo la UI: incluso sin sesión devuelve 401.
        $client->restart();
        foreach ($paths as $name => $path) {
            $client->request('GET', $prefixA.$path);
            self::assertResponseStatusCodeSame(401, $name.' GET anónimo');
        }
    }

    private static function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }
}
""";


def _command(argv: list[str], env: dict[str, str], timeout: int) -> subprocess.CompletedProcess[str]:
    """Ejecuta procesos sin shell y preserva stdout/stderr para diagnóstico."""
    return subprocess.run(
        argv, cwd=ROOT, env=env, capture_output=True, text=True,
        check=False, timeout=timeout,
    )


class ReadmeControllerHttpIntegrationTests(unittest.TestCase):
    def test_real_symfony_mariadb_tenant_and_permission_guards(self) -> None:
        """Ejecuta los cuatro controladores en kernel Symfony y DB descartable."""
        for path in CONTROLLERS:
            self.assertTrue(path.is_file(), f"Controlador faltante: {path}")
        self.assertEqual("test", os.environ.get("APP_ENV"), "Solo APP_ENV=test")
        url = os.environ.get("DATABASE_URL", "")
        parsed = urlsplit(url)
        database_name = parsed.path.lstrip("/")
        self.assertIn(parsed.scheme, ("mysql", "mariadb"))
        self.assertIn(parsed.hostname, ("127.0.0.1", "localhost", "::1"))
        self.assertTrue(
            database_name.startswith("condor_")
            and ("test" in database_name or "_ci_" in database_name),
            "Sólo se permite MariaDB local y descartable; nunca Hostinger.",
        )
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP 8.5 CLI es obligatorio")
        env = dict(os.environ)
        env["APP_ENV"] = "test"
        env.setdefault("APP_SECRET", secrets.token_hex(32))
        vendor = ROOT / "vendor"
        # El CI ejecuta el validador de documentación después de esta
        # prueba: no dejar README ajenos de un Composer/PHPUnit temporal.
        # Limpiar únicamente vendor generado por este runner descartable.
        if os.environ.get("GITHUB_ACTIONS") == "true" and not vendor.exists():
            def cleanup_generated_vendor() -> None:
                if vendor.is_dir() and not vendor.is_symlink():
                    shutil.rmtree(vendor)
            self.addCleanup(cleanup_generated_vendor)

        if not (ROOT / "vendor/autoload.php").is_file():
            composer = shutil.which("composer")
            self.assertIsNotNone(composer, "Composer requerido para kernel HTTP real")
            install = _command(
                [composer, "install", "--no-interaction", "--prefer-dist", "--no-progress"],
                env, 480,
            )
            self.assertEqual(0, install.returncode, install.stdout + install.stderr)
        self.assertTrue(PHPUNIT.is_file(), "No se encontró PHPUnit real")
        migrations = _command(
            [php, "bin/console", "doctrine:migrations:migrate", "--no-interaction"],
            env, 180,
        )
        self.assertEqual(0, migrations.returncode, migrations.stdout + migrations.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-readme-http-") as directory:
            fixture = Path(directory) / "ReadmeControllersHttpSmokeTest.php"
            fixture.write_text(HTTP_SCENARIO, encoding="utf-8")
            lint = _command([php, "-l", str(fixture)], env, 30)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = _command([str(PHPUNIT), str(fixture)], env, 240)
            log = result.stdout + result.stderr
            if log:
                print(log, end="" if log.endswith("\n") else "\n")
            self.assertEqual(0, result.returncode, log)
            self.assertRegex(log, r"OK\s*\(\s*1 test,", "PHPUnit no ejecutó WebTestCase")


if __name__ == "__main__":
    unittest.main()

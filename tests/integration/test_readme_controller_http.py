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
from urllib.parse import parse_qsl, urlsplit

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
        $noRead = new User('readme-no-read-'.$suffix.'@example.test', 'Sin lectura A');
        $ownerMembershipA = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);
        $ownerMembershipB = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);
        $viewerMembership = new Membership($tenantA, $viewer, 'ADMIN');
        $noReadMembership = new Membership($tenantA, $noRead, 'ADMIN');
        $viewRole = new Role($tenantA, 'README lectura '.$suffix, [
            'orders.view', 'customers.view', 'inventory.view',
        ]);
        $viewAssignment = new BranchRoleAssignment($viewerMembership, $branchA, $viewRole);
        $noReadRole = new Role($tenantA, 'README sin lectura '.$suffix, [
            'orders.create', 'customers.create', 'inventory.create',
        ]);
        $noReadAssignment = new BranchRoleAssignment($noReadMembership, $branchA, $noReadRole);
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
            $ownerA, $ownerB, $viewer, $noRead, $ownerMembershipA, $ownerMembershipB,
            $viewerMembership, $noReadMembership, $viewRole, $viewAssignment,
            $noReadRole, $noReadAssignment, $sourceA, $sourceB,
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
        // Antes de crear un proveedor, el recurso ausente devuelve lista vacía,
        // no inventa un proveedor ni consulta registros de otro tenant.
        $client->request('GET', $prefixA.'/purchasing/suppliers');
        self::assertResponseStatusCodeSame(200, 'GET A sin proveedores');
        $absentSuppliers = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($absentSuppliers);
        self::assertSame([], $absentSuppliers['suppliers'] ?? null);
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

        // La pertenencia a la misma empresa/sede y los permisos de escritura
        // NO conceden por sí solos permiso de lectura en ningún GET.
        $client->loginUser($noRead);
        foreach ($paths as $name => $path) {
            $client->request('GET', $prefixA.$path);
            self::assertResponseStatusCodeSame(403, $name.' GET sin permiso de lectura');
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


def _require_disposable_doctrine_destination(environment: dict[str, str]) -> None:
    """Valida el destino efectivo de Doctrine DBAL ANTES de cualquier efecto.

    Doctrine admite parámetros query que prevalecen sobre host/base del URL;
    por eso solo se aceptan opciones no relacionadas con el destino.
    """
    if environment.get("APP_ENV") != "test":
        raise ValueError("Se exige APP_ENV=test para preparar la base descartable.")
    try:
        parsed = urlsplit(environment.get("DATABASE_URL", ""))
        destination = parsed.path.removeprefix("/")
        if (
            parsed.scheme not in ("mysql", "mariadb")
            or parsed.hostname not in ("127.0.0.1", "localhost", "::1")
            or parsed.port != 3306
            or parsed.fragment
            or not re.fullmatch(r"condor_[a-z0-9_]+", destination)
            or ("test" not in destination and "_ci_" not in destination)
        ):
            raise ValueError("Destino MariaDB fuera de la base local descartable.")
        options = parse_qsl(
            parsed.query, keep_blank_values=True, strict_parsing=True,
            max_num_fields=8,
        )
        recognized = set()
        for key, value in options:
            if key in recognized:
                raise ValueError("Opción repetida en DATABASE_URL.")
            recognized.add(key)
            if key == "serverVersion" and re.fullmatch(
                r"mariadb-[0-9]+(?:\.[0-9]+){1,2}", value,
            ):
                continue
            if key == "charset" and value == "utf8mb4":
                continue
            # Bloquea host/dbname/port/socket/path/url, alias codificados y
            # cualquier override desconocido de la configuración DBAL.
            raise ValueError("Opción de DATABASE_URL no permitida.")
    except ValueError as exc:
        raise ValueError("No se autoriza conexión a destino Doctrine no aislado.") from exc


class ReadmeControllerHttpIntegrationTests(unittest.TestCase):
    def test_guard_rechaza_overrides_antes_de_composer_migraciones_o_fixtures(self) -> None:
        allowed = "mysql://root@127.0.0.1:3306/condor_ci_bridge_28"
        for url in (
            allowed,
            allowed + "?serverVersion=mariadb-11.4.0&charset=utf8mb4",
            allowed + "?charset=utf8mb4",
        ):
            with self.subTest(allowed=url.split("?")[1:] or ["no-query"]):
                _require_disposable_doctrine_destination({
                    "APP_ENV": "test", "DATABASE_URL": url,
                })

        rejected = [
            allowed + "?host=remote.example.test",
            allowed + "?HOST=remote.example.test",
            allowed + "?ho%73t=remote.example.test",
            allowed + "?dbname=production",
            allowed + "?dbName=production",
            allowed + "?unix_socket=/var/run/mysql/mysql.sock",
            allowed + "?socket=/var/run/mysql/mysql.sock",
            allowed + "?path=/tmp/prod.sock",
            allowed + "?port=3307",
            allowed + "?url=mysql://remote.example.test/production",
            allowed + "?serverVersion=mariadb-11.4.0&host=remote.example.test",
            allowed + "?charset=utf8mb4&dbname=production",
            allowed + "?charset=utf8mb4&charset=utf8mb4",
            allowed + "?charset=utf8mb4&unrecognized=x",
            allowed + "?serverVersion=mariadb-11.4.0&serverVersion=mariadb-11.5.0",
            allowed + "?unknown",
            "mysql://root@remote.example.test:3306/condor_test",
            "mysql://root@127.0.0.1:3306/production",
            "mysql://root@127.0.0.1:3306/condor_ci_bridge_28/other",
            "mysql://root@127.0.0.1:3307/condor_test",
            "mysql://root@127.0.0.1:3306/condor_test#fragment",
        ]
        for url in rejected:
            with self.subTest(rejected=url):
                with self.assertRaises(ValueError):
                    _require_disposable_doctrine_destination({
                        "APP_ENV": "test", "DATABASE_URL": url,
                    })
        for environment in ("prod", "dev", "", "production"):
            with self.subTest(app_env=environment):
                with self.assertRaises(ValueError):
                    _require_disposable_doctrine_destination({
                        "APP_ENV": environment, "DATABASE_URL": allowed,
                    })

    def test_real_symfony_mariadb_tenant_and_permission_guards(self) -> None:
        """Ejecuta los cuatro controladores en kernel Symfony y DB descartable."""
        # Primer paso de test: falla antes de Composer, migrations o fixtures.
        _require_disposable_doctrine_destination(dict(os.environ))
        for path in CONTROLLERS:
            self.assertTrue(path.is_file(), f"Controlador faltante: {path}")
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

#!/usr/bin/env python3
"""#1608: testigos HTTP Symfony y contrato runtime D-011/D-023/D-025.

Los hosts .example.test son entradas de KernelBrowser: no se consulta DNS.
La base y los secretos efímeros los proporciona exclusivamente el job CI test.
"""

from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/verificacion/decisiones-01-integral.md"

HTTP_FIXTURE = r"""<?php
declare(strict_types=1);

use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Decisiones01IntegralHttpTest extends WebTestCase
{
    public function testD011PublicBrandAndSeoThroughActualKernel(): void
    {
        $client = self::createClient();
        $host = ['HTTP_HOST' => 'www.condorapp.com.co'];
        $paths = ['/', '/producto', '/modulos', '/soluciones', '/seguridad', '/empezar'];

        foreach ($paths as $path) {
            $crawler = $client->request('GET', $path, server: $host);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertSame('es-CO', $crawler->filter('html')->attr('lang'), $path);
            $title = $crawler->filter('title')->text();
            self::assertStringContainsString('Condor', $title, $path);
            self::assertStringContainsString('Condor App',
                $crawler->filter('footer.site-footer')->text(), $path);
            self::assertCount(1, $crawler->filter('link[rel="canonical"]'), $path);
            self::assertSame(
                'https://www.condorapp.com.co'.$path,
                $crawler->filter('link[rel="canonical"]')->attr('href'),
                $path,
            );
            $description = $crawler->filter('meta[name="description"]')->attr('content');
            self::assertIsString($description, $path);
            self::assertNotSame('', trim($description), $path);
        }
    }

    public function testD025HostSlugIsolationAndCanonicalThroughKernel(): void
    {
        $client = self::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('SITIO_A_'.$suffix, 'integral-a-'.$suffix);
        $tenantB = new Tenant('SITIO_B_'.$suffix, 'integral-b-'.$suffix);
        $hostA = 'a-'.$suffix.'.example.test';
        $hostUnverified = 'pending-'.$suffix.'.example.test';
        foreach ([
            $tenantA,
            $tenantB,
            new StorefrontProfile($tenantA, 'TITULAR_A_'.$suffix, 'Detalle A '.$suffix),
            new StorefrontProfile($tenantB, 'TITULAR_B_'.$suffix, 'Detalle B '.$suffix),
            new TenantDomain($tenantA, $hostA, true, true),
            new TenantDomain($tenantA, $hostUnverified, false, false),
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $platform = ['HTTP_HOST' => 'www.condorapp.com.co'];
        $client->request('GET', '/'.$tenantA->slug(), server: $platform);
        self::assertResponseStatusCodeSame(200);
        $pageA = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('TITULAR_A_'.$suffix, $pageA);
        self::assertStringNotContainsString('TITULAR_B_'.$suffix, $pageA);
        self::assertStringContainsString('href="https://'.$hostA.'/"', $pageA);

        $client->request('GET', '/'.$tenantB->slug(), server: $platform);
        self::assertResponseStatusCodeSame(200);
        $pageB = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('TITULAR_B_'.$suffix, $pageB);
        self::assertStringNotContainsString('TITULAR_A_'.$suffix, $pageB);
        self::assertStringContainsString(
            'href="https://www.condorapp.com.co/'.$tenantB->slug().'"',
            $pageB,
        );

        // La portada del dominio verificado se sirve por app_home, no por /slug.
        $client->request('GET', '/', server: ['HTTP_HOST' => $hostA]);
        self::assertResponseStatusCodeSame(200);
        $domainPage = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('TITULAR_A_'.$suffix, $domainPage);
        self::assertStringNotContainsString('TITULAR_B_'.$suffix, $domainPage);
        self::assertStringContainsString('href="https://'.$hostA.'/"', $domainPage);

        // Dominio ajeno/pendiente no tiene acceso; ruta slug no cruza hosts.
        $negatives = [
            ['/'.'noexiste-'.$suffix, 'www.condorapp.com.co'],
            ['/', 'desconocido-'.$suffix.'.example.test'],
            ['/', $hostUnverified],
            ['/'.$tenantB->slug(), $hostA],
            ['/'.$tenantA->slug(), 'desconocido-'.$suffix.'.example.test'],
            ['/producto', $hostA],
        ];
        foreach ($negatives as [$path, $host]) {
            $client->request('GET', $path, server: ['HTTP_HOST' => $host]);
            self::assertResponseStatusCodeSame(404, $host.$path);
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('TITULAR_B_'.$suffix, $body);
            self::assertStringNotContainsString('TITULAR_A_'.$suffix, $body);
        }
    }
}
"""


class VerificacionDecisiones01IntegralTests(unittest.TestCase):
    def test_d011_d025_http_kernel_reales(self) -> None:
        """Los dos testigos invocan routing, Doctrine, controller y Twig reales."""
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP 8.5 obligatorio, sin skip")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Composer/PHPUnit requerido, sin skip")
        with tempfile.TemporaryDirectory(prefix="condor-d011-d025-") as directory:
            fixture = Path(directory) / "Decisiones01IntegralHttpTest.php"
            fixture.write_text(HTTP_FIXTURE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                text=True, capture_output=True, check=False, timeout=30,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            actual = subprocess.run(
                [str(runner), str(fixture)], cwd=ROOT,
                text=True, capture_output=True, check=False, timeout=180,
            )
            self.assertEqual(0, actual.returncode, actual.stdout + actual.stderr)
            self.assertRegex(actual.stdout + actual.stderr, r"(?:OK|Tests: 2)")

    def test_d023_php_symfony_mariadb_contract(self) -> None:
        """Arranque local reproducible: no simula ni acredita AWS o DNS."""
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        requirements = composer["require"]
        self.assertEqual("^8.5", requirements["php"])
        for package in ("symfony/framework-bundle", "symfony/console",
                        "symfony/http-kernel"):
            if package in requirements:
                self.assertEqual("7.4.*", requirements[package])
        self.assertEqual("7.4.*", requirements["symfony/framework-bundle"])
        self.assertIn("^4", requirements["doctrine/dbal"])

        dbal = (ROOT / "config/packages/doctrine.yaml").read_text(encoding="utf-8")
        self.assertRegex(dbal, r"url:\s*['\"]?%env\(resolve:DATABASE_URL\)%")
        self.assertIn("charset: utf8mb4", dbal)
        self.assertTrue((ROOT / "migrations").is_dir())
        self.assertTrue((ROOT / "public/index.php").is_file())
        self.assertTrue((ROOT / "config/bootstrap.php").is_file())
        self.assertIn("App\\Kernel", (ROOT / "public/index.php").read_text())
        self.assertTrue((ROOT / "vendor/autoload.php").is_file())

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP 8.5 obligatorio")
        bootstrap = (
            'require "vendor/autoload.php"; '
            'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) exit(6); '
            'if (!str_starts_with(\\Symfony\\Component\\HttpKernel\\Kernel::VERSION, "7.4.")) exit(7); '
            '$kernel = new \\App\\Kernel("test", false); '
            '$kernel->boot(); '
            'if ($kernel->getContainer()->getParameter("kernel.environment") !== "test") exit(8); '
            '$kernel->shutdown(); echo "PHP_SYMFONY_BOOT_OK";'
        )
        result = subprocess.run(
            [php, "-r", bootstrap], cwd=ROOT, text=True,
            capture_output=True, check=False, timeout=120,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("PHP_SYMFONY_BOOT_OK", result.stdout)

    def test_documentacion_diferencia_evidencia_local_de_live(self) -> None:
        content = REPORT.read_text(encoding="utf-8")
        for token in ("D-011", "D-023", "D-025", "LOCAL_PARTIAL",
                      "UNKNOWN", "Hostinger", "AWS", "www.condorapp.com.co",
                      "test_d011_d025_http_kernel_reales", "test_d023_php_symfony_mariadb_contract"):
            with self.subTest(token=token):
                self.assertIn(token, content)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", content)
        self.assertNotIn("ACREDITADO EN PRODUCCIÓN", content)
        self.assertNotIn("docs/matriz-cobertura.md modificado", content)


if __name__ == "__main__":
    unittest.main()

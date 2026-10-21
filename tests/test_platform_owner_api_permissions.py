#!/usr/bin/env python3
"""#774 AC-01: autorización HTTP real de la API del propietario de plataforma.

Ejecuta Symfony WebTestCase sobre una base de pruebas aislada, con dos tenants
e identidades sintéticas. El fixture PHP temporal evita reclamar rutas ajenas.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE_CONTROLLER = ROOT / "src/Http/Controller/PlatformOwnerApiController.php"
STAFF_CONTROLLER = ROOT / "src/Http/Controller/PlatformStaffController.php"
SECURITY = ROOT / "config/packages/security.yaml"
HTTP_RUNNER = ROOT / "vendor/bin/simple-phpunit"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlataformaOwnerApiPermisosHttpSmokeTest extends WebTestCase
{
    public function testPlatformOwnerOnlyAndTenantAdminsFailClosed(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(6));

        $tenantA = new Tenant('Cliente A '.$suffix, 'platform-a-'.$suffix);
        $tenantB = new Tenant('Cliente B '.$suffix, 'platform-b-'.$suffix);
        $owner = new User(
            'platform-owner-'.$suffix.'@example.test',
            'Propietario plataforma', [User::ROLE_PLATFORM_OWNER],
        );
        $adminA = new User('tenant-a-'.$suffix.'@example.test', 'Administrador A');
        $adminB = new User('tenant-b-'.$suffix.'@example.test', 'Administrador B');
        $memberA = new Membership($tenantA, $adminA, Membership::ROLE_OWNER);
        $memberB = new Membership($tenantB, $adminB, Membership::ROLE_OWNER);
        foreach ([
            $tenantA, $tenantB, $owner, $adminA, $adminB, $memberA, $memberB,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $route = '/adminpl0n3r/api/staff';
        $createTenant = '/adminpl0n3r/api/tenants';
        $attempt = [
            'name' => 'Prohibido '.$suffix,
            'slug' => 'forbidden-'.$suffix,
            'legal_name' => 'Prohibido',
            'branch_name' => 'Principal',
            'owner_email' => 'denied-'.$suffix.'@example.test',
            'owner_name' => 'Prohibido',
        ];

        // Without an authenticated principal, the API must return 401,
        // never redirect into a login page or leak the platform overview.
        $client->request('GET', $route);
        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString(
            'Cliente A '.$suffix, (string) $client->getResponse()->getContent(),
        );
        $client->jsonRequest('POST', $createTenant, $attempt);
        self::assertResponseStatusCodeSame(401);

        // A platform owner can read both tenants: this is deliberately
        // *not* equivalent to a tenant administrator's membership rights.
        $client->loginUser($owner);
        $client->request('GET', $route);
        self::assertResponseStatusCodeSame(200);
        $response = json_decode(
            (string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($response);
        self::assertArrayHasKey('tenant_options', $response);
        $visibleIds = array_column($response['tenant_options'], 'id');
        self::assertContains($tenantA->id(), $visibleIds);
        self::assertContains($tenantB->id(), $visibleIds);

        // Only the authentication/authorization gate is tested here:
        // a forged CSRF token blocks even an authenticated platform owner.
        $client->jsonRequest(
            'POST', $createTenant, $attempt,
            ['HTTP_X_CSRF_TOKEN' => 'forged'],
        );
        self::assertResponseStatusCodeSame(403);

        foreach ([$adminA, $adminB] as $tenantAdmin) {
            $client->loginUser($tenantAdmin);
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame(403);
            $denied = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Cliente A '.$suffix, $denied);
            self::assertStringNotContainsString('Cliente B '.$suffix, $denied);
            self::assertStringNotContainsString(
                'platform-owner-'.$suffix.'@example.test', $denied,
            );

            // The shared parent controller is reached by *two* distinct
            // endpoint classes: reads and tenant creation; both must deny.
            $client->jsonRequest(
                'POST', $createTenant, $attempt,
                ['HTTP_X_CSRF_TOKEN' => 'forged'],
            );
            self::assertResponseStatusCodeSame(403);
        }

        // Restart removes the session: an earlier privileged login cannot
        // silently carry over between requests after logout/restart.
        $client->restart();
        $client->request('GET', $route);
        self::assertResponseStatusCodeSame(401);
        $client->jsonRequest('POST', $createTenant, $attempt);
        self::assertResponseStatusCodeSame(401);

        $inspection = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $inspection);
        self::assertNull(
            $inspection->getRepository(Tenant::class)
                ->findOneBy(['slug' => 'forbidden-'.$suffix]),
            'A denied request must not persist a cross-tenant resource.',
        );
    }
}
"""


class PlataformaOwnerApiPermisosTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (BASE_CONTROLLER, STAFF_CONTROLLER, SECURITY):
            self.assertTrue(path.is_file(), f"Archivo requerido ausente: {path}")

        base = BASE_CONTROLLER.read_text(encoding="utf-8")
        staff = STAFF_CONTROLLER.read_text(encoding="utf-8")
        security = SECURITY.read_text(encoding="utf-8")
        self.assertIn("User::ROLE_PLATFORM_OWNER", base)
        self.assertIn("!$user->isActive()", base)
        self.assertIn("$this->platformOwner()", staff)
        self.assertIn("roles: ROLE_PLATFORM_OWNER", security)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para prueba HTTP real")
        self.assertTrue(HTTP_RUNNER.is_file(), "Falta PHPUnit de Symfony")
        with tempfile.TemporaryDirectory(prefix="condor-owner-api-http-") as directory:
            scenario = Path(directory) / "PlataformaOwnerApiPermisosHttpSmokeTest.php"
            scenario.write_text(PHP_SCENARIO, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(scenario)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=30,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            execution = subprocess.run(
                [str(HTTP_RUNNER), str(scenario)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=180,
            )
            log = execution.stdout + execution.stderr
            self.assertEqual(0, execution.returncode, log)
            # A successful CLI exit with zero PHPUnit tests is not evidence.
            self.assertRegex(log, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

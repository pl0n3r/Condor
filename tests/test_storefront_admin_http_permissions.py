#!/usr/bin/env python3
"""#756 AC-01: HTTP real de StorefrontAdmin con tenants y roles sintéticos.

El fixture PHPUnit se genera fuera del repositorio para respetar la V3
exclusiva del test Python. Sin DNS, credenciales, usuarios o datos reales.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/StorefrontAdminController.php"
EXISTING_HTTP_TESTS = ROOT / "tests/php/Http/StorefrontAdminControllerTest.php"

PHP_SMOKE = r"""<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontAdminPermissionsHttpSmokeTest extends WebTestCase
{
    public function testOwnerViewerAndForeignTenantStayIsolated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $a = new Tenant('Tienda A '.$suffix, 'storefront-a-'.$suffix);
        $b = new Tenant('Tienda B '.$suffix, 'storefront-b-'.$suffix);
        $branch = new Branch($a, 'Principal', 'principal-'.$suffix, null, true);
        $branchB = new Branch($b, 'Principal', 'principal-b-'.$suffix, null, true);

        $ownerA = new User('storefront-owner-a-'.$suffix.'@example.test', 'Owner A');
        $ownerB = new User('storefront-owner-b-'.$suffix.'@example.test', 'Owner B');
        $viewer = new User('storefront-viewer-'.$suffix.'@example.test', 'Viewer A');
        $blocked = new User('storefront-blocked-'.$suffix.'@example.test', 'Blocked A');
        $memberA = new Membership($a, $ownerA, Membership::ROLE_OWNER);
        $memberB = new Membership($b, $ownerB, Membership::ROLE_OWNER);
        $viewerMember = new Membership($a, $viewer, 'ADMIN');
        $blockedMember = new Membership($a, $blocked, 'ADMIN');
        $role = new Role($a, 'Leer sitio '.$suffix, ['site.view']);
        $assignment = new BranchRoleAssignment($viewerMember, $branch, $role);
        $profileA = new StorefrontProfile($a, 'A_VISIBLE_'.$suffix, 'Solo tienda A');
        $profileB = new StorefrontProfile($b, 'B_PRIVADO_'.$suffix, 'Solo tienda B');
        $hostB = 'storefront-b-'.$suffix.'.example.test';
        $domainB = new TenantDomain($b, $hostB, true, true);

        foreach ([
            $a, $b, $branch, $branchB, $ownerA, $ownerB, $viewer, $blocked,
            $memberA, $memberB, $viewerMember, $blockedMember, $role,
            $assignment, $profileA, $profileB, $domainB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($ownerA);
        $page = $client->request('GET', '/admin/storefront');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('A_VISIBLE_'.$suffix, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('B_PRIVADO_'.$suffix, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($hostB, (string) $client->getResponse()->getContent());

        $csrf = $page->filter('form.storefront-admin-form input[name="_token"]')->first()->attr('value');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $valid = [
            '_token' => $csrf,
            '_action' => 'profile',
            'headline' => 'A_ACTUALIZADA_'.$suffix,
            'description' => 'Identidad publicada en A solamente',
        ];
        $client->request('POST', '/admin/storefront', $valid);
        self::assertResponseRedirects('/admin/storefront?saved=profile');
        $em->refresh($profileA);
        self::assertSame('A_ACTUALIZADA_'.$suffix, $profileA->headline());

        // Ningún identificador/host del tenant B puede entrar por mass assignment.
        foreach ([
            ['tenant_id' => $b->id()],
            ['domain' => $hostB],
            ['hostname' => $hostB],
            ['description' => ['array inesperado']],
        ] as $injection) {
            $client->request('POST', '/admin/storefront', array_replace($valid, $injection));
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('POST', '/admin/storefront', array_replace($valid, ['_token' => 'invalido']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/storefront', array_replace($valid, ['_action' => 'borrar']));
        self::assertResponseStatusCodeSame(422);
        $em->refresh($profileA);
        $em->refresh($profileB);
        self::assertSame('A_ACTUALIZADA_'.$suffix, $profileA->headline());
        self::assertSame('B_PRIVADO_'.$suffix, $profileB->headline());
        self::assertSame($b->id(), $domainB->tenant()->id());
        self::assertSame($hostB, $domainB->hostname());

        // Un permiso delegado de lectura no se convierte en permiso global de escritura.
        $client->loginUser($viewer);
        $client->request('GET', '/admin/storefront');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorNotExists('form.storefront-admin-form');
        $client->request('POST', '/admin/storefront', $valid);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($blocked);
        $client->request('GET', '/admin/storefront');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/storefront', $valid);
        self::assertResponseStatusCodeSame(403);

        // El propietario B no puede seleccionar el tenant A mediante query/form.
        $client->loginUser($ownerB);
        $client->request('GET', '/admin/storefront?tenant_id='.$a->id());
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('B_PRIVADO_'.$suffix, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('A_ACTUALIZADA_'.$suffix, (string) $client->getResponse()->getContent());
        $client->request('POST', '/admin/storefront', array_replace($valid, ['tenant_id' => $a->id()]));
        self::assertResponseStatusCodeSame(422);

        // El host verificado de B publica solo a B, no el perfil de A.
        $client->request('GET', '/', server: ['HTTP_HOST' => $hostB]);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('B_PRIVADO_'.$suffix, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('A_ACTUALIZADA_'.$suffix, (string) $client->getResponse()->getContent());
        $em->refresh($profileA);
        $em->refresh($profileB);
        self::assertSame('A_ACTUALIZADA_'.$suffix, $profileA->headline());
        self::assertSame('B_PRIVADO_'.$suffix, $profileB->headline());
    }
}
"""


class TiendaAdminPermisosHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), "Falta el controlador real")
        self.assertTrue(EXISTING_HTTP_TESTS.is_file(), "Faltan pruebas HTTP existentes")
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido para pruebas HTTP reales")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit instalado requerido")

        for path in (CONTROLLER,):
            result = subprocess.run(
                [php, "-l", str(path)], cwd=ROOT, check=False,
                capture_output=True, text=True, timeout=25,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-storefront-http-") as temp:
            fixture = Path(temp) / "StorefrontAdminPermissionsHttpSmokeTest.php"
            fixture.write_text(PHP_SMOKE, encoding="utf-8")
            syntax = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                check=False, capture_output=True, text=True, timeout=25,
            )
            self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
            result = subprocess.run(
                [str(runner), str(fixture)], cwd=ROOT, check=False,
                capture_output=True, text=True, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertRegex(result.stdout + result.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

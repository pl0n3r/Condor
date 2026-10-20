#!/usr/bin/env python3
"""#760 AC-01: real Symfony HTTP denials for tenant admins, with synthetic tenants."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlatformStaffController.php"
BASE_CONTROLLER = ROOT / "src/Http/Controller/PlatformOwnerApiController.php"
EXISTING_SUITE = ROOT / "tests/php/Http/PlatformStaffControllerTest.php"

PHP_SMOKE = r"""<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformStaffPermissionsHttpSmokeTest extends WebTestCase
{
    public function testOnlyPlatformOwnerCanReadOrMutateStaff(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(5));

        $tenantA = new Tenant('Scope A '.$suffix, 'staff-scope-a-'.$suffix);
        $tenantB = new Tenant('Scope B '.$suffix, 'staff-scope-b-'.$suffix);
        $platformOwner = new User(
            'platform-owner-'.$suffix.'@example.test',
            'Dueño de plataforma', [User::ROLE_PLATFORM_OWNER],
        );
        $adminA = new User('admin-a-'.$suffix.'@example.test', 'Admin tenant A');
        $adminB = new User('admin-b-'.$suffix.'@example.test', 'Admin tenant B');
        $membershipA = new Membership($tenantA, $adminA, Membership::ROLE_OWNER);
        $membershipB = new Membership($tenantB, $adminB, Membership::ROLE_OWNER);

        foreach ([
            $tenantA, $tenantB, $platformOwner, $adminA, $adminB,
            $membershipA, $membershipB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $base = '/adminpl0n3r/api/staff';
        $client->loginUser($platformOwner);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $visible = json_decode(
            (string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($visible);
        self::assertArrayHasKey('staff', $visible);
        self::assertArrayHasKey('catalog', $visible);
        self::assertArrayHasKey('tenant_options', $visible);
        $visibleIds = array_column($visible['tenant_options'], 'id');
        self::assertContains($tenantA->id(), $visibleIds);
        self::assertContains($tenantB->id(), $visibleIds);

        // Tenant owners are intentionally privileged within their own tenant,
        // but never inherit the platform owner's staff-management authority.
        foreach ([$adminA, $adminB] as $tenantAdmin) {
            $client->loginUser($tenantAdmin);
            $client->request('GET', $base);
            self::assertResponseStatusCodeSame(403);

            $email = 'rejected-'.$tenantAdmin->id().'@example.test';
            $client->jsonRequest('POST', $base.'/invitations', [
                'email' => $email,
                'display_name' => 'Prohibido',
                'grants' => [[
                    'tenant_id' => $tenantA->id(),
                    'module' => 'catalog',
                    'actions' => ['view'],
                ]],
            ], ['HTTP_X_CSRF_TOKEN' => 'forged']);
            self::assertResponseStatusCodeSame(403);

            $client->jsonRequest('PUT', $base.'/'.$platformOwner->id().'/grants', [
                'grants' => [[
                    'tenant_id' => $tenantB->id(),
                    'module' => 'catalog',
                    'actions' => ['view'],
                ]],
            ], ['HTTP_X_CSRF_TOKEN' => 'forged']);
            self::assertResponseStatusCodeSame(403);

            $client->request(
                'POST',
                $base.'/'.$platformOwner->id().'/invitations/resend',
                server: ['HTTP_X_CSRF_TOKEN' => 'forged'],
            );
            self::assertResponseStatusCodeSame(403);

            $client->request(
                'DELETE',
                $base.'/'.$platformOwner->id().'/invitations',
                server: ['HTTP_X_CSRF_TOKEN' => 'forged'],
            );
            self::assertResponseStatusCodeSame(403);

            self::assertNull(
                $em->getRepository(User::class)->findOneBy(['email' => $email]),
                'Forbidden staff creation must not persist a user.',
            );
        }
    }
}
"""


class StaffPlataformaPermisosHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Real GET and all mutation routes reject both tenant admins with 403."""
        self.assertTrue(CONTROLLER.is_file())
        self.assertTrue(BASE_CONTROLLER.is_file())
        self.assertTrue(EXISTING_SUITE.is_file())

        source = CONTROLLER.read_text(encoding="utf-8")
        guard = BASE_CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("User::ROLE_PLATFORM_OWNER", guard)
        self.assertIn("!$user->isActive()", guard)
        self.assertIn("throw new AccessDeniedHttpException()", guard)
        self.assertIn("private function staff(string $staffId): User", source)
        self.assertIn("$owner = $this->platformOwner();", source)
        # Do not confuse CSRF-only 403 with platform-role authorization.
        for route_method in ("invite", "replaceGrants", "resend", "revoke"):
            marker = "public function " + route_method + "("
            section = source.split(marker, 1)[1].split("\n    }", 1)[0]
            self.assertLess(
                section.index("$this->platformOwner()"),
                section.index("$this->requireManagementCsrf("),
            )

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is needed for the HTTP acceptance test")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Symfony/PHPUnit dependencies are required")
        with tempfile.TemporaryDirectory(prefix="condor-platform-staff-http-") as directory:
            smoke = Path(directory) / "PlatformStaffPermissionsHttpSmokeTest.php"
            smoke.write_text(PHP_SMOKE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(smoke)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(runner), str(smoke)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

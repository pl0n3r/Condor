#!/usr/bin/env python3
"""Contract and HTTP checks for Condor #1694 (owner-only, fail-closed audit)."""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlatformAuditController.php"
PANEL = ROOT / "frontend/admin/PlatformAuditPanel.tsx"
BASE = ROOT / "src/Http/Controller/PlatformOwnerApiController.php"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformAuditPermissionsHttpSmokeTest extends WebTestCase
{
    public function testOwnerCanReadAndTenantAdminsCannotReadOrLeakContext(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $owner = new User('owner-'.$suffix.'@example.test', 'Platform Owner', [User::ROLE_PLATFORM_OWNER]);
        $tenant = new Tenant('Synthetic '.$suffix, 'audit-'.$suffix);
        $admin = new User('tenant-'.$suffix.'@example.test', 'Tenant Admin');
        $member = new Membership($tenant, $admin, Membership::ROLE_OWNER);
        $event = new PlatformAuditEvent(
            $owner->id(), null, 'commercial.plan.updated', 'commercial_plan', 'synthetic-'.$suffix,
            ['old' => ['version' => 1, 'email' => 'private@example.test', 'token' => 'SHOULD_NOT_LEAK'],
             'new' => ['version' => 2, 'secret' => 'SHOULD_NOT_LEAK']],
        );
        foreach ([$owner, $tenant, $admin, $member, $event] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $path = '/adminpl0n3r/api/auditoria';

        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($admin);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request('GET', $path, ['actor' => $owner->id(), 'action' => 'commercial.plan.updated']);
        self::assertResponseStatusCodeSame(200);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('private@example.test', $body);
        self::assertStringNotContainsString('SHOULD_NOT_LEAK', $body);
        self::assertStringNotContainsString('tenant-', $body);
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['total']);
        self::assertSame(1, $data['items'][0]['before']['version']);
        self::assertSame(2, $data['items'][0]['after']['version']);
        self::assertArrayNotHasKey('email', $data['items'][0]['before']);
        self::assertArrayNotHasKey('token', $data['items'][0]['before']);

        $client->request('GET', $path, ['page' => '0']);
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', $path, ['from' => '2026-02-30']);
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', $path, ['actor' => $owner->id(), 'action' => 'other.event']);
        self::assertResponseStatusCodeSame(200);
        $other = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $other['total']);
    }
}
"""

class PlatformAuditoriaTests(unittest.TestCase):
    def test_claims_are_read_only_and_redacted(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        panel = PANEL.read_text(encoding="utf-8")
        base = BASE.read_text(encoding="utf-8")
        self.assertIn("$this->platformOwner();", source)
        self.assertIn("ROLE_PLATFORM_OWNER", base)
        self.assertIn("condor_platform_audit_event", source)
        self.assertNotIn("condor_audit_event", source)
        self.assertIn("PUBLIC_FIELDS", source)
        self.assertIn("publicSnapshot(", source)
        self.assertIn("private, no-store", source)
        for component in ("PageHeader", "MetricStrip", "Toolbar", "DataTable", "StatusPill", "SidePanel"):
            self.assertIn(component, panel)
        self.assertIn("No tienes permiso", panel)
        self.assertIn("No hay cambios", panel)
        self.assertIn("Cargando auditoría", panel)

    def test_real_http_permissions_and_privacy(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP is required for the real HTTP acceptance test")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Symfony dependencies required")
        for path in (CONTROLLER,):
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT, capture_output=True, text=True, timeout=30)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        with tempfile.TemporaryDirectory(prefix="platform-audit-") as tmp:
            smoke = Path(tmp) / "PlatformAuditPermissionsHttpSmokeTest.php"
            smoke.write_text(PHP_SCENARIO, encoding="utf-8")
            result = subprocess.run([str(runner), str(smoke)], cwd=ROOT,
                capture_output=True, text=True, timeout=180)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

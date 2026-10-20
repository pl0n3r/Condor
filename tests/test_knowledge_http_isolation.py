#!/usr/bin/env python3
"""#758 AC-01: exercise the real KnowledgeController routes with two test tenants.

The Symfony HTTP fixture uses only synthetic local database records. No
production accounts, external network calls, or real customer data are needed.
"""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/KnowledgeController.php"

HTTP_FIXTURE = r'''<?php

declare(strict_types=1);

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class KnowledgeHttpIsolationSmokeTest extends WebTestCase
{
    public function testKnowledgeHttpIsolationAndInvalidInputs(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenantA = new Tenant('Conocimiento A '.$suffix, 'knowledge-a-'.$suffix);
        $tenantB = new Tenant('Conocimiento B '.$suffix, 'knowledge-b-'.$suffix);
        $userA = new User('knowledge-a-'.$suffix.'@example.test', 'Usuario A');
        $userB = new User('knowledge-b-'.$suffix.'@example.test', 'Usuario B');
        $orphan = new User('knowledge-orphan-'.$suffix.'@example.test', 'Sin empresa');
        $membershipA = new Membership($tenantA, $userA, Membership::ROLE_OWNER);
        $membershipB = new Membership($tenantB, $userB, Membership::ROLE_OWNER);
        foreach ([$tenantA, $tenantB, $userA, $userB, $orphan, $membershipA, $membershipB] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        // Public endpoints must reveal only published public knowledge.
        $client->request('GET', '/ayuda');
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-knowledge-scope="global"', $html);
        self::assertStringContainsString('data-knowledge-id="account-access"', $html);
        self::assertStringNotContainsString('data-knowledge-id="admin-context"', $html);
        self::assertStringNotContainsString('data-knowledge-id="onboarding-draft"', $html);
        self::assertStringNotContainsString('data-knowledge-id="staff-operations"', $html);
        self::assertStringNotContainsString($tenantA->name(), $html);
        self::assertStringNotContainsString($tenantB->name(), $html);

        $client->request('GET', '/faq?module=help');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(
            'data-knowledge-id="account-access"',
            (string) $client->getResponse()->getContent()
        );

        // Modules cannot promote customer/staff content into a public answer.
        $client->request('GET', '/ayuda?module=admin');
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="knowledge-handoff"', $html);
        self::assertStringNotContainsString('data-knowledge-id="admin-context"', $html);
        $client->request('GET', '/ayuda?module[]=help');
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/faq?module=../admin');
        self::assertResponseStatusCodeSame(400);

        // Unauthenticated callers cannot see contextual help.
        $client->request('GET', '/admin/ayuda');
        self::assertNotSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(
            $tenantA->name(), (string) $client->getResponse()->getContent()
        );

        $client->loginUser($userA);
        // A caller cannot select B's tenant through request query parameters.
        $client->request('GET', '/admin/ayuda?module=admin&tenant_id='.$tenantB->id());
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-knowledge-scope="tenant:'.$tenantA->id().'"', $html);
        self::assertStringContainsString($tenantA->name(), $html);
        self::assertStringContainsString('data-knowledge-id="admin-context"', $html);
        self::assertStringNotContainsString($tenantB->name(), $html);
        self::assertStringNotContainsString('tenant:'.$tenantB->id(), $html);
        self::assertStringNotContainsString('data-knowledge-id="account-access"', $html);

        $client->request('GET', '/admin/ayuda?module=help');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(
            'data-testid="knowledge-handoff"',
            (string) $client->getResponse()->getContent()
        );
        $client->request('GET', '/admin/ayuda?module[]=admin');
        self::assertResponseStatusCodeSame(400);

        // Switching identities must switch the server-selected tenant.
        $client->restart();
        $client->loginUser($userB);
        $client->request('GET', '/admin/ayuda?module=admin&tenant_id='.$tenantA->id());
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-knowledge-scope="tenant:'.$tenantB->id().'"', $html);
        self::assertStringContainsString($tenantB->name(), $html);
        self::assertStringNotContainsString($tenantA->name(), $html);
        self::assertStringNotContainsString('tenant:'.$tenantA->id(), $html);

        // Having a valid login without tenant membership is not authorization.
        $client->restart();
        $client->loginUser($orphan);
        $client->request('GET', '/admin/ayuda');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(
            $tenantA->name(), (string) $client->getResponse()->getContent()
        );
        self::assertStringNotContainsString(
            $tenantB->name(), (string) $client->getResponse()->getContent()
        );
    }
}
'''


class ConocimientoAislamientoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI es obligatorio para HTTP AC-01")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit Symfony real es obligatorio")

        syntax = subprocess.run(
            [php, "-l", str(CONTROLLER)],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-knowledge-http-") as tmp:
            fixture = Path(tmp) / "KnowledgeHttpIsolationSmokeTest.php"
            fixture.write_text(HTTP_FIXTURE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            tested = subprocess.run(
                [str(runner), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(
                0, tested.returncode, tested.stdout + tested.stderr
            )


if __name__ == "__main__":
    unittest.main()

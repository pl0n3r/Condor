#!/usr/bin/env python3
"""#1693: HTTP real con identidades SaaS sintéticas, sin movimientos de pago."""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlatformSaasRevenueController.php"
PANEL = ROOT / "frontend/admin/PlatformRevenuePanel.tsx"
RUNNER = ROOT / "vendor/bin/simple-phpunit"
SCENARIO = r"""<?php

declare(strict_types=1);

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SaasRevenueOwnerHttpTest extends WebTestCase
{
    public function testOwnerOnlyWindowAndTariff(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(6));
        $owner = new User('revenue-owner-'.$suffix.'@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]);
        $other = new User('revenue-tenant-'.$suffix.'@example.test', 'Tenant admin');
        $tenant = new Tenant('Empresa sintética '.$suffix, 'revenue-'.$suffix);
        $plan = new Plan('test-plan-'.$suffix, 'Plan sintético');
        $version = new PlanVersion($plan, 1, 199900, 1999000, false, [], new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lifecycle = new SubscriptionLifecycle($tenant->id(), $version, SubscriptionState::Active, new DateTimeImmutable('2026-11-05T12:00:00.000000Z'));
        foreach ([$owner, $other, $tenant, $plan, $version] as $row) $manager->persist($row);
        $manager->persist(Subscription::fromLifecycle($lifecycle, new DateTimeImmutable('2026-11-05T12:00:01Z')));
        $manager->flush();
        $url = '/adminpl0n3r/api/ingresos?from=2026-11-01&to=2026-11-30';
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($other);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($owner);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $out = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ready', $out['status']);
        self::assertSame(199900, $out['totals']['new']);
        self::assertSame(199900, $out['totals']['mrr_delta']);
        self::assertSame('COP', $out['currency']);
        self::assertSame($tenant->id(), $out['movements'][0]['tenant_id']);
        self::assertSame($plan->key(), $out['movements'][0]['plan_key']);
        self::assertSame('effective', $out['movements'][0]['recognition']);
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        $encoded = strtolower(json_encode($out, JSON_THROW_ON_ERROR));
        foreach (['@example.test', 'password', 'credit_card', 'invoice', 'payment_token'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
        $client->request('GET', '/adminpl0n3r/api/ingresos?from=2026-12-01&to=2026-12-31');
        self::assertResponseStatusCodeSame(200);
        $empty = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $empty['movements']);
        self::assertSame(0, $empty['totals']['new']);
        foreach (['from=2026-02-30&to=2026-03-01', 'from=2026-12-31&to=2026-01-01', 'from=2020-01-01&to=2026-11-30', 'from=2026-11-01&to=2026-11-30&plan=%25'] as $bad) {
            $client->request('GET', '/adminpl0n3r/api/ingresos?'.$bad);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('POST', $url);
        self::assertResponseStatusCodeSame(405);
    }
}
"""


class SaasRevenueOwnerTests(unittest.TestCase):
    def test_contract_and_http(self) -> None:
        source = CONTROLLER.read_text(encoding="utf-8")
        panel = PANEL.read_text(encoding="utf-8")
        for required in ("$this->platformOwner()", "SaasRevenueMovements::derive",
                         "Cache-Control", "methods: ['GET']", "incomplete_tenant_scope"):
            self.assertIn(required, source)
        for required in ("PageHeader", "MetricStrip", "Toolbar", "DataTable", "SidePanel",
                         "Sin tarifa", "credentials: 'same-origin'"):
            self.assertIn(required, panel)
        php = shutil.which("php")
        self.assertIsNotNone(php)
        self.assertTrue(RUNNER.is_file(), "Requiere vendor/bin/simple-phpunit")
        with tempfile.TemporaryDirectory(prefix="condor-1693-synthetic-") as tmp:
            test = Path(tmp) / "SaasRevenueOwnerHttpTest.php"
            test.write_text(SCENARIO, encoding="utf-8")
            for path in (CONTROLLER, test):
                lint = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                      capture_output=True, text=True, timeout=30, check=False)
                self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)
            run = subprocess.run([str(RUNNER), str(test)], cwd=ROOT,
                                 capture_output=True, text=True, timeout=180, check=False)
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)
            self.assertRegex(run.stdout + run.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

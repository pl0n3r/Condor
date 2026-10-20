#!/usr/bin/env python3
"""#776: negativas HTTP reales del configurador y precio calculado en servidor."""

from __future__ import annotations

import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlanConfiguratorController.php"
QUOTE_SERVICE = ROOT / "src/Application/Commercial/PlanQuoteService.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfiguradorPlanNegativasHttpSmokeTest extends WebTestCase
{
    public function testRechazosYPrecioCalculadoPorServidor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new CommercialCatalogSeeder($em))->seed();

        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Cliente A '.$suffix, 'quote-a-'.$suffix);
        $tenantB = new Tenant('Cliente B '.$suffix, 'quote-b-'.$suffix);
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        $endpoint = '/api/public/configurator/quote';
        $baseline = [
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'quantities' => ['users' => 12, 'locations' => 3, 'companies' => 1],
            'addons' => [],
        ];

        // Each request uses a different synthetic IP to avoid mistaking the
        // public rate limiter's 429 for a business-rule rejection (422).
        $invalid = [
            ['plan' => 'nonexistent-plan'],
            ['vertical' => 'nonexistent-vertical'],
            ['cycle' => 'weekly'],
            ['quantities' => ['users' => 0]],
            ['quantities' => ['locations' => -1]],
            ['quantities' => ['users' => '12']],
            ['quantities' => ['unknown_scale' => 1]],
            ['addons' => ['unlisted-addon']],
            ['addons' => ['extra-user']],
            ['unknown_field' => 'forbidden'],
        ];
        foreach ($invalid as $index => $change) {
            $client->jsonRequest(
                'POST',
                $endpoint,
                array_replace($baseline, $change),
                server: ['REMOTE_ADDR' => '198.51.100.'.(40 + $index)],
            );
            self::assertResponseStatusCodeSame(
                422,
                'Entrada inválida #'.$index.' debe rechazarse por el servidor.',
            );
        }

        // A valid add-on for another vertical must not be force-selected.
        $client->jsonRequest('POST', $endpoint, [
            'plan' => 'pro',
            'vertical' => 'legal',
            'cycle' => 'monthly',
            'quantities' => ['users' => 30, 'locations' => 10, 'companies' => 5],
            'addons' => ['production-lite'],
        ], server: ['REMOTE_ADDR' => '198.51.100.60']);
        self::assertResponseStatusCodeSame(422);

        $before = $em->getRepository(Quote::class)->count([]);
        $client->jsonRequest(
            'POST',
            $endpoint,
            $baseline + ['total' => 1],
            server: ['REMOTE_ADDR' => '198.51.100.61'],
        );
        self::assertResponseStatusCodeSame(200);
        $response = (string) $client->getResponse()->getContent();
        $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('quote', $payload);
        self::assertSame(229700, $payload['quote']['total_amount']);
        self::assertNotSame(1, $payload['quote']['total_amount']);
        self::assertFalse($payload['quote']['proposal_required']);
        self::assertSame($before, $em->getRepository(Quote::class)->count([]));
        self::assertStringNotContainsString('Cliente A '.$suffix, $response);
        self::assertStringNotContainsString('Cliente B '.$suffix, $response);
    }
}
"""


class ConfiguradorPlanNegativasHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Runs a Symfony WebTestCase against isolated synthetic test tenants."""
        self.assertTrue(CONTROLLER.is_file(), "Falta el controlador HTTP")
        self.assertTrue(QUOTE_SERVICE.is_file(), "Falta el cálculo comercial")
        php = shutil.which("php")
        self.assertIsNotNone(php, "Se requiere PHP CLI para una prueba HTTP real")
        self.assertTrue(PHPUNIT.is_file(), "Se requieren dependencias PHPUnit")
        with tempfile.TemporaryDirectory(prefix="condor-plan-http-") as directory:
            scenario = Path(directory) / "ConfiguradorPlanNegativasHttpSmokeTest.php"
            scenario.write_text(PHP_SCENARIO, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(scenario)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            run = subprocess.run(
                [str(PHPUNIT), str(scenario)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            log = run.stdout + run.stderr
            self.assertEqual(0, run.returncode, log)
            self.assertRegex(log, r"OK\s*\(\s*1 test,", "No se ejecutó el test HTTP")


if __name__ == "__main__":
    unittest.main()

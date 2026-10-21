#!/usr/bin/env python3
"""Condor #780: contrato HTTP real de telemetría del configurador.

Symfony WebTestCase + PHPUnit + base efímera del CI; solo nombres, IP y
tenants sintéticos. No contacta endpoints de producción.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlanConfiguratorTelemetryController.php"
RECORDER = ROOT / "src/Infrastructure/Observability/FunctionalSignalRecorder.php"
PHPUNIT = ROOT / "vendor/bin/simple-phpunit"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** HTTP real, dos tenants sintéticos y cero datos personales persistidos. */
final class ConfiguratorTelemetryHttpSmokeTest extends WebTestCase
{
    public function testPrivacyBodyBoundsAndRateLimitOnPublicRoute(): void
    {
        $client = static::createClient();
        // Mantiene el storage del rate limiter entre peticiones HTTP.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new CommercialCatalogSeeder($em))->seed();

        $suffix = bin2hex(random_bytes(6));
        $aName = 'PRIVADO_TENANT_A_'.$suffix;
        $bName = 'PRIVADO_TENANT_B_'.$suffix;
        $tenantA = new Tenant($aName, 'telemetry-a-'.$suffix);
        $tenantB = new Tenant($bName, 'telemetry-b-'.$suffix);
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();

        $repository = $em->getRepository(FunctionalSignal::class);
        $before = [];
        foreach ($repository->findBy(['type' => FunctionalSignal::CONFIGURATOR_FUNNEL]) as $signal) {
            $before[$signal->id()] = true;
        }
        $route = '/api/public/configurator/events';
        $headersA = [
            'REMOTE_ADDR' => '198.51.100.181',
            'HTTP_X_TENANT_ID' => $tenantA->id(),
        ];
        $headersB = [
            'REMOTE_ADDR' => '198.51.100.182',
            'HTTP_X_TENANT_ID' => $tenantB->id(),
        ];
        $invalidCases = [
            [['event' => 'start', 'email' => 'private@example.test'], 422],
            [['event' => 'start', 'tenant' => $tenantB->id()], 422],
            [['event' => 'start', 'name' => $aName], 422],
            [['event' => 'unknown'], 422],
            [['event' => ['start']], 422],
            [['cycle' => 'monthly'], 422],
            [['event' => 'start'.str_repeat(' ', 8192)], 413],
        ];
        foreach ($invalidCases as $index => [$body, $expected]) {
            $client->jsonRequest('POST', $route, $body, server: [
                'REMOTE_ADDR' => '198.51.100.'.(190 + $index),
                'HTTP_X_TENANT_ID' => $tenantA->id(),
            ]);
            self::assertResponseStatusCodeSame($expected);
        }
        $client->request(
            'POST', $route, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.200'] + $headersA,
            '{this is not JSON',
        );
        self::assertResponseStatusCodeSame(400);

        // Una carga válida se guarda sin correo, IP, tenant ni headers.
        $event = [
            'event' => 'plan_selected',
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'addon' => 'extra-user',
            'step' => 'plan',
        ];
        $client->jsonRequest('POST', $route, $event, server: $headersA);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => true], json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        ));

        // La rate limit de test es 2/h por IP, sin privilegios por tenant.
        $client->jsonRequest('POST', $route, ['event' => 'completion'], server: $headersB);
        self::assertResponseStatusCodeSame(202);
        $client->jsonRequest('POST', $route, ['event' => 'completion'], server: $headersB);
        self::assertResponseStatusCodeSame(202);
        $client->jsonRequest('POST', $route, ['event' => 'completion'], server: $headersB);
        self::assertResponseStatusCodeSame(429);
        self::assertNotEmpty($client->getResponse()->headers->get('Retry-After'));

        $newSignals = [];
        foreach ($repository->findBy(['type' => FunctionalSignal::CONFIGURATOR_FUNNEL]) as $signal) {
            if (!isset($before[$signal->id()])) {
                $newSignals[] = $signal;
            }
        }
        self::assertCount(3, $newSignals, 'Rechazos y rate limit no deben generar señales.');
        $contexts = [];
        foreach ($newSignals as $signal) {
            self::assertNull($signal->tenantId(), 'Telemetry pública nunca ata tenant.');
            $context = $signal->context();
            foreach (array_keys($context) as $field) {
                self::assertContains($field, [
                    'event', 'plan', 'vertical', 'cycle', 'addon', 'step',
                ]);
            }
            $json = json_encode($context, JSON_THROW_ON_ERROR);
            foreach ([$aName, $bName, $tenantA->id(), $tenantB->id(), '@', '198.51.100'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
            $contexts[] = $context;
        }
        self::assertContains($event, $contexts);
    }
}
"""


class TelemetriaConfiguradorHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Ejecutar el controlador auténtico por HTTP con PHPUnit/Symfony."""
        for path in (CONTROLLER, RECORDER):
            self.assertTrue(path.is_file(), f"Falta fuente HTTP: {path}")
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI obligatorio")
        self.assertTrue(PHPUNIT.is_file(), "Symfony PHPUnit obligatorio")

        with tempfile.TemporaryDirectory(prefix="condor-telemetry-http-") as folder:
            fixture = Path(folder) / "ConfiguratorTelemetryHttpSmokeTest.php"
            fixture.write_text(PHP_SCENARIO, encoding="utf-8")
            for stage, cmd, timeout in (
                ("lint", [php, "-l", str(fixture)], 30),
                ("HTTP real", [str(PHPUNIT), str(fixture)], 180),
            ):
                result = subprocess.run(
                    cmd, cwd=ROOT, capture_output=True, text=True,
                    check=False, timeout=timeout,
                )
                output = result.stdout + result.stderr
                self.assertEqual(0, result.returncode, f"{stage}: {output}")
                if stage == "HTTP real":
                    self.assertRegex(output, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

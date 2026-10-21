#!/usr/bin/env python3
"""Condor #777: HTTP real de /precios con dos tenants sintéticos.

La prueba ejecuta el controlador público mediante Symfony WebTestCase y la
base descartable de PHPUnit. La fixture PHP se crea fuera del checkout; no
interactúa con usuarios, datos o endpoints de producción.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/CommercialPricingController.php"
READER = ROOT / "src/Application/Commercial/CommercialCatalogReader.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\PlanVersionTimeline;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CommercialPricingHttpSmokeTest extends WebTestCase
{
    public function testPublicCatalogIsAuthoritativeAndTenantSafe(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        (new CommercialCatalogSeeder($manager))->seed();
        $suffix = bin2hex(random_bytes(5));
        $privateA = 'PRIVATE_TENANT_A_'.$suffix;
        $privateB = 'PRIVATE_TENANT_B_'.$suffix;
        $tenantA = new Tenant($privateA, 'public-prices-a-'.$suffix);
        $tenantB = new Tenant($privateB, 'public-prices-b-'.$suffix);
        $userA = new User('public-prices-a-'.$suffix.'@example.test', 'Owner A');
        $userB = new User('public-prices-b-'.$suffix.'@example.test', 'Owner B');
        foreach ([
            $tenantA, $tenantB, $userA, $userB,
            new Membership($tenantA, $userA, Membership::ROLE_OWNER),
            new Membership($tenantB, $userB, Membership::ROLE_OWNER),
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        // Los precios públicos proceden del catálogo común, no del tenant
        // de la sesión, de query parameters ni de montos enviados por HTTP.
        $catalog = (new CommercialCatalogReader(
            $manager, new PlanVersionTimeline(),
        ))->current(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        self::assertCount(4, $catalog);
        $beforePlans = $manager->getRepository(Plan::class)->count([]);
        $beforeVersions = $manager->getRepository(PlanVersion::class)->count([]);

        $client->request('GET', '/precios');
        self::assertResponseStatusCodeSame(200);
        $anonymousCards = self::cards($client);
        self::assertCount(4, $anonymousCards);
        self::assertSame(
            array_column($catalog, 'key'),
            array_column($anonymousCards, 'key'),
            'Las tarjetas conservan exactamente las claves de catálogo.',
        );
        foreach ($catalog as $index => $plan) {
            $card = $anonymousCards[$index];
            self::assertSame((string) $plan['version'], $card['version']);
            self::assertStringContainsString($plan['name'], $card['text']);
            if ($plan['quote_required']) {
                self::assertStringContainsString('Solicitar propuesta', $card['text']);
                self::assertStringNotContainsString('0 COP', $card['text']);
            } else {
                self::assertStringContainsString(
                    $plan['currency'].' '.number_format($plan['monthly_amount'], 0, ',', '.'),
                    $card['text'],
                );
                if ($plan['annual_amount'] !== null) {
                    self::assertStringContainsString(
                        $plan['currency'].' '.number_format($plan['annual_amount'], 0, ',', '.'),
                        $card['text'],
                    );
                }
            }
        }
        self::assertSame(1, count(array_filter(
            $catalog, static fn (array $plan): bool => $plan['quote_required'],
        )));

        // El cliente intenta modificar totales, moneda, plan y tenant. La
        // ruta GET debe ignorarlo y mostrar las mismas tarjetas del servidor.
        $client->request(
            'GET',
            '/precios?plan=enterprise&monthly_amount=1&annual_amount=1'
                .'&currency=USD&total=1&tenant='.$tenantB->id(),
            [], [], ['HTTP_X_TENANT_ID' => $tenantB->id()],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame($anonymousCards, self::cards($client));
        self::assertPrivateNamesAbsent($client, [$privateA, $privateB]);

        // Dos owners de tenants independientes nunca obtienen datos privados
        // ajenos a través del catálogo público; tampoco pueden personalizar
        // un precio enviado desde el cliente.
        foreach ([$userA, $userB] as $user) {
            $client->loginUser($user);
            $client->request(
                'GET',
                '/precios?total=1&monthly_amount=1&tenant='.$tenantA->id(),
                [], [], ['HTTP_X_TENANT_ID' => $tenantB->id()],
            );
            self::assertResponseStatusCodeSame(200);
            self::assertSame($anonymousCards, self::cards($client));
            self::assertPrivateNamesAbsent($client, [$privateA, $privateB]);
        }

        // La ruta de catálogo solo acepta GET. Ningún verbo mutante puede
        // convertir precios enviados por el usuario en importes persistidos.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $client->request($method, '/precios', ['monthly_amount' => '1']);
            self::assertResponseStatusCodeSame(405);
            self::assertPrivateNamesAbsent($client, [$privateA, $privateB]);
        }
        $client->restart();
        $client->request('GET', '/precios');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($anonymousCards, self::cards($client));

        $freshManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);
        self::assertSame($beforePlans, $freshManager->getRepository(Plan::class)->count([]));
        self::assertSame(
            $beforeVersions,
            $freshManager->getRepository(PlanVersion::class)->count([]),
            'Ninguna petición HTTP debe escribir el catálogo comercial.',
        );
    }

    /** @return list<array{key:string,version:string,text:string}> */
    private static function cards(KernelBrowser $client): array
    {
        return $client->getCrawler()->filter('article.pricing-card')->each(
            static function (Crawler $card): array {
                return [
                    'key' => (string) $card->attr('data-plan-key'),
                    'version' => (string) $card->attr('data-plan-version'),
                    'text' => trim((string) preg_replace('/\s+/u', ' ', $card->text())),
                ];
            },
        );
    }

    /** @param list<string> $names */
    private static function assertPrivateNamesAbsent(KernelBrowser $client, array $names): void
    {
        $body = (string) $client->getResponse()->getContent();
        foreach ($names as $name) {
            self::assertStringNotContainsString($name, $body);
        }
        self::assertStringNotContainsString('@example.test', $body);
    }
}
"""


class PreciosComercialesHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for source in (CONTROLLER, READER):
            self.assertTrue(source.is_file(), str(source))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI real es obligatorio")
        self.assertTrue(RUNNER.is_file(), "PHPUnit real es obligatorio")

        with tempfile.TemporaryDirectory(prefix="condor-commercial-http-") as directory:
            fixture = Path(directory) / "CommercialPricingHttpSmokeTest.php"
            fixture.write_text(PHP_SCENARIO, encoding="utf-8")
            commands = (
                ("lint PHP", [php, "-l", str(fixture)], 25),
                ("test HTTP", [str(RUNNER), str(fixture)], 180),
            )
            results: dict[str, str] = {}
            for stage, command, timeout in commands:
                run = subprocess.run(
                    command, cwd=ROOT, capture_output=True, text=True,
                    check=False, timeout=timeout,
                )
                evidence = run.stdout + run.stderr
                self.assertEqual(0, run.returncode, f"{stage}: {evidence}")
                results[stage] = evidence
            self.assertRegex(results["test HTTP"], r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()

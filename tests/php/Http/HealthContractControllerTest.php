<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Organization\Entity\Tenant;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** AC-01: contrato HTTP real, sin sesión y con tenants sintéticos. */
final class HealthContractControllerTest extends WebTestCase
{
    public function testAnonymousHealthContractDoesNotLeakSecretsOrTenantContext(): void
    {
        $client = static::createClient();
        /** @var array{version: string} $config */
        $config = require dirname(__DIR__, 3).'/config/version.php';
        $expectedVersion = $config['version'];
        $suffix = bin2hex(random_bytes(4));
        $tenants = [
            new Tenant('Salud sintética A '.$suffix, 'health-a-'.$suffix),
            new Tenant('Salud sintética B '.$suffix, 'health-b-'.$suffix),
        ];
        $secret = 'secret-sintetico-NO-PUBLICAR-'.$suffix;
        $known = null;

        // Cabeceras de tenant no autentican ni alteran el monitor global.
        foreach ($tenants as $tenant) {
            $client->restart();
            $client->request('GET', '/health', server: [
                'HTTP_X_TENANT_ID' => $tenant->id(),
                'HTTP_X_SYNTHETIC_SECRET' => $secret,
            ]);
            self::assertResponseStatusCodeSame(200);
            $response = $client->getResponse();
            self::assertStringContainsString(
                'application/json', (string) $response->headers->get('Content-Type'),
            );
            $raw = (string) $response->getContent();
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            $keys = array_keys($data);
            sort($keys);
            self::assertSame(['release_sha', 'schema_up_to_date', 'status', 'version'], $keys);
            self::assertSame('ok', $data['status']);
            self::assertSame($expectedVersion, $data['version']);
            self::assertIsString($data['release_sha']);
            self::assertMatchesRegularExpression('/\A(?:[0-9a-f]{40}|dev)\z/D', $data['release_sha']);
            self::assertIsBool($data['schema_up_to_date']);
            self::assertStringNotContainsString($tenant->id(), $raw);
            self::assertStringNotContainsString($secret, $raw);
            self::assertStringNotContainsString('DATABASE_URL', $raw);
            self::assertStringNotContainsString('pending_migrations', $raw);
            self::assertStringNotContainsString('stack_trace', $raw);
            self::assertStringNotContainsString('php_version', $raw);
            if ($known !== null) {
                self::assertSame($known, $data);
            }
            $known = $data;
        }

        // La ruta es pública sin cookie ni sesión previa.
        $client->restart();
        $client->request('GET', '/health');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($known, json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        ));

        // La ruta es read-only. Una petición de escritura no abre mutaciones.
        $client->request('POST', '/health');
        self::assertResponseStatusCodeSame(405);
    }
}

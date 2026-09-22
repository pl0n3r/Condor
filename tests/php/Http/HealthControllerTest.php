<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthPublishesSafeSchemaState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertArrayHasKey('schema_up_to_date', $payload);
        self::assertIsBool($payload['schema_up_to_date']);
        self::assertArrayNotHasKey('pending_migrations', $payload);
        self::assertArrayNotHasKey('database_url', $payload);
    }
}

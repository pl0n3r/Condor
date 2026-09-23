<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Doctrine\ORM\EntityManagerInterface;
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
        self::assertTrue(
            $payload['schema_up_to_date'],
            'La base de pruebas migrada debe reportar el esquema al día.',
        );
        self::assertArrayNotHasKey('pending_migrations', $payload);
        self::assertArrayNotHasKey('database_url', $payload);
    }

    public function testHealthReportsFalseWhenExecutedMigrationIsUnavailable(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $version = 'DoctrineMigrations\\Version20991231235958';
        $connection->insert('doctrine_migration_versions', [
            'version' => $version,
            'executed_at' => date('Y-m-d H:i:s'),
            'execution_time' => 1,
        ]);

        try {
            $client->request('GET', '/health');

            self::assertResponseIsSuccessful();
            $payload = json_decode(
                (string) $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertIsArray($payload);
            self::assertFalse($payload['schema_up_to_date'] ?? true);
            self::assertArrayNotHasKey('pending_migrations', $payload);
        } finally {
            $connection->delete(
                'doctrine_migration_versions',
                ['version' => $version],
            );
        }
    }

    public function testHealthReportsFalseWhenAConfiguredMigrationIsPending(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $migrationPath = $projectDir.'/migrations/Version20991231235959.php';
        $migration = <<<'PHP'
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20991231235959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migración efímera para probar /health.';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
PHP;

        self::assertFalse(file_exists($migrationPath));
        file_put_contents($migrationPath, $migration);

        try {
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
            self::assertFalse($payload['schema_up_to_date'] ?? true);
            self::assertArrayNotHasKey('pending_migrations', $payload);
        } finally {
            @unlink($migrationPath);
        }
    }

}

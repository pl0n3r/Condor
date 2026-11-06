<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261212120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Verifies the actual MariaDB schema produced by Doctrine migrations. */
final class PlatformIntegrationSettingsMigrationTest extends KernelTestCase
{
    public function testPlatformTablesHaveOnlyGlobalKeysAndValueFreeAudit(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $columns = $db->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'condor_platform_integration_setting'",
        );
        foreach ([
            'setting_key', 'plain_value', 'encrypted_value', 'nonce',
            'key_version', 'secret_suffix', 'updated_at', 'updated_by',
        ] as $expected) {
            self::assertContains($expected, $columns);
        }
        self::assertNotContains('tenant_id', $columns);
        $auditColumns = $db->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'condor_platform_setting_audit'",
        );
        self::assertEqualsCanonicalizing(
            ['id', 'setting_key', 'action', 'actor_id', 'occurred_at'],
            $auditColumns,
        );
        self::assertNotContains('secret', $auditColumns);
    }

    public function testUpSkipsAlreadyAppliedCompatibleTables(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        require_once dirname(__DIR__, 4).'/migrations/Version20261212120000.php';
        $migration = new Version20261212120000($db, new NullLogger());
        $migration->up(new Schema());
        self::assertSame([], $migration->getSql());
    }

    public function testRollbackRejectsEachPopulatedTableAndSchedulesEmptyRollback(): void
    {
        require_once dirname(__DIR__, 4).'/migrations/Version20261212120000.php';
        foreach ([[1, 0], [0, 1]] as [$settings, $audits]) {
            $migration = $this->rollbackMigrationForCounts($settings, $audits);
            $schema = new Schema();
            $migration->preDown($schema);
            try {
                $migration->down($schema);
                self::fail('La reversión aceptó una tabla poblada.');
            } catch (IrreversibleMigration) {
                self::assertSame([], $migration->getSql());
            }
        }

        $migration = $this->rollbackMigrationForCounts(0, 0);
        $schema = new Schema();
        $migration->preDown($schema);
        $migration->down($schema);
        self::assertSame(
            ['DROP TABLE condor_platform_setting_audit', 'DROP TABLE condor_platform_integration_setting'],
            array_map(static fn ($sql): string => $sql->getStatement(), $migration->getSql()),
        );
        $migration->postDown($schema);
    }

    private function rollbackMigrationForCounts(int $settings, int $audits): Version20261212120000
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql) use ($settings, $audits): int {
                if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
                    return 1;
                }
                if (str_contains($sql, 'condor_platform_integration_setting')) {
                    return $settings;
                }
                if (str_contains($sql, 'condor_platform_setting_audit')) {
                    return $audits;
                }
                throw new \LogicException('SQL inesperado en prueba de reversión.');
            },
        );
        return new Version20261212120000($db, new NullLogger());
    }
}

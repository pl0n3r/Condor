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
            try {
                $migration->down($schema);
                self::fail('La reversión aceptó una tabla poblada.');
            } catch (IrreversibleMigration) {
                self::assertSame([], $migration->getSql());
            }
        }

        $migration = $this->rollbackMigrationForCounts(0, 0);
        $schema = new Schema();
        $migration->down($schema);
        $sql = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
        self::assertCount(6, $sql);
        self::assertStringContainsString('GET_LOCK', $sql[0]);
        self::assertStringContainsString('COUNT(*) FROM condor_platform_integration_setting', $sql[1]);
        self::assertStringContainsString('COUNT(*) FROM condor_platform_setting_audit', $sql[1]);
        self::assertStringContainsString(
            'DROP TABLE condor_platform_setting_audit, condor_platform_integration_setting', $sql[1],
        );
        self::assertStringContainsString('PREPARE', $sql[2]);
        self::assertStringContainsString('EXECUTE', $sql[3]);
        self::assertStringContainsString('RELEASE_LOCK', $sql[5]);
    }

    public function testPartialMigrationCreatesOnlyMissingAuditTable(): void
    {
        require_once dirname(__DIR__, 4).'/migrations/Version20261212120000.php';
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params): array {
                if (($params[0] ?? '') === 'condor_platform_setting_audit') {
                    return [];
                }
                if (str_contains($sql, 'information_schema.COLUMNS')) {
                    $columns = [
                        ['setting_key', 'varchar', 100, 'NO', ''],
                        ['plain_value', 'text', 0, 'YES', ''],
                        ['encrypted_value', 'text', 0, 'YES', ''],
                        ['nonce', 'varchar', 32, 'YES', ''],
                        ['key_version', 'smallint', 0, 'YES', ''],
                        ['secret_suffix', 'varchar', 4, 'YES', ''],
                        ['updated_at', 'datetime', 0, 'NO', ''],
                        ['updated_by', 'varchar', 80, 'NO', ''],
                    ];
                    return array_map(static fn (array $c): array => [
                        'COLUMN_NAME' => $c[0],
                        'DATA_TYPE' => $c[1],
                        'CHARACTER_MAXIMUM_LENGTH' => $c[2],
                        'IS_NULLABLE' => $c[3],
                        'EXTRA' => $c[4],
                    ], $columns);
                }
                if (str_contains($sql, 'information_schema.STATISTICS')) {
                    return [['INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => 'setting_key', 'NON_UNIQUE' => 0]];
                }
                throw new \LogicException('Consulta inesperada de estructura.');
            },
        );
        $db->method('fetchOne')->willReturn('InnoDB');
        $migration = new Version20261212120000($db, new NullLogger());
        $migration->up(new Schema());
        $sql = array_map(static fn ($q): string => $q->getStatement(), $migration->getSql());
        self::assertCount(1, $sql);
        self::assertStringContainsString('CREATE TABLE condor_platform_setting_audit', $sql[0]);
    }

    public function testExportedRollbackDoesNotDropTablesAfterLateWrite(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        require_once dirname(__DIR__, 4).'/migrations/Version20261212120000.php';

        $suffix = bin2hex(random_bytes(5));
        $settingsTable = 'fixture_platform_settings_'.$suffix;
        $auditTable = 'fixture_platform_audit_'.$suffix;
        $db->executeStatement('CREATE TEMPORARY TABLE '.$settingsTable.' (id INTEGER NOT NULL)');
        $db->executeStatement('CREATE TEMPORARY TABLE '.$auditTable.' (id INTEGER NOT NULL)');
        try {
            $migration = $this->rollbackMigrationForCounts(0, 0);
            $migration->down(new Schema());
            $queries = array_map(
                static fn ($query): string => str_replace(
                    ['condor_platform_integration_setting', 'condor_platform_setting_audit'],
                    [$settingsTable, $auditTable],
                    $query->getStatement(),
                ),
                $migration->getSql(),
            );

            // The script was exported when both tables were empty, but
            // a later write must prevent dropping either table.
            $db->executeStatement('INSERT INTO '.$settingsTable.' (id) VALUES (1)');
            $denied = false;
            try {
                foreach ($queries as $sql) {
                    $db->executeQuery($sql);
                }
            } catch (\Doctrine\DBAL\Exception) {
                $denied = true;
            }
            self::assertTrue($denied, 'La exportación SQL borró datos posteriores al dry-run.');
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$settingsTable));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$auditTable));

            $db->fetchOne("SELECT RELEASE_LOCK('condor_platform_settings_schema')");
            $db->executeStatement('DELETE FROM '.$settingsTable);
            foreach ($queries as $sql) {
                $db->executeQuery($sql);
            }
            try {
                $db->fetchOne('SELECT COUNT(*) FROM '.$settingsTable);
                self::fail('La exportación segura no ejecutó el rollback vacío.');
            } catch (\Doctrine\DBAL\Exception) {
                // Both empty temporary tables were actually dropped.
            }
        } finally {
            $db->fetchOne("SELECT RELEASE_LOCK('condor_platform_settings_schema')");
            $db->executeStatement('DROP TEMPORARY TABLE IF EXISTS '.$auditTable);
            $db->executeStatement('DROP TEMPORARY TABLE IF EXISTS '.$settingsTable);
        }
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

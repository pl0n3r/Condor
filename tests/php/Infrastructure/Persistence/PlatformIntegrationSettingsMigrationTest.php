<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
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

    public function testRollbackGuardProhibitsDroppingPopulatedAuditOrSettings(): void
    {
        $path = dirname(__DIR__, 4).'/migrations/Version20261212120000.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('IrreversibleMigration', $source);
        self::assertStringContainsString(
            'SELECT COUNT(*) FROM condor_platform_integration_setting', $source,
        );
        self::assertStringContainsString(
            'SELECT COUNT(*) FROM condor_platform_setting_audit', $source,
        );
    }
}

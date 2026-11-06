<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use RuntimeException;
use Throwable;

/** #1726: additive platform-scoped settings; never migrates real credentials. */
final class Version20261212120000 extends AbstractMigration // NOSONAR -- Doctrine naming
{
    private const SCHEMA_LOCK = 'condor_platform_settings_schema';

    private bool $schemaLockHeld = false;

    public function getDescription(): string
    {
        return 'Ajustes globales cifrados y auditoría sin valores, reversión protegida.';
    }

    public function isTransactional(): bool
    {
        return false; // MariaDB DDL cannot be rolled back atomically.
    }

    public function up(Schema $schema): void
    {
        // MariaDB DDL commits independently: recover safely if the first CREATE
        // succeeded but the second failed before Doctrine recorded this version.
        if (!$this->existingTableIsCompatible(
            'condor_platform_integration_setting',
            [
                'setting_key' => ['varchar', 100, 'NO', ''],
                'plain_value' => ['text', 0, 'YES', ''],
                'encrypted_value' => ['text', 0, 'YES', ''],
                'nonce' => ['varchar', 32, 'YES', ''],
                'key_version' => ['smallint', 0, 'YES', ''],
                'secret_suffix' => ['varchar', 4, 'YES', ''],
                'updated_at' => ['datetime', 0, 'NO', ''],
                'updated_by' => ['varchar', 80, 'NO', ''],
            ],
            ['PRIMARY' => ['setting_key']],
        )) {
            $this->addSql(<<<'SQL'
                CREATE TABLE condor_platform_integration_setting (
                    setting_key VARCHAR(100) NOT NULL,
                    plain_value TEXT DEFAULT NULL,
                    encrypted_value TEXT DEFAULT NULL,
                    nonce VARCHAR(32) DEFAULT NULL,
                    key_version SMALLINT DEFAULT NULL,
                    secret_suffix VARCHAR(4) DEFAULT NULL,
                    updated_at DATETIME NOT NULL,
                    updated_by VARCHAR(80) NOT NULL,
                    PRIMARY KEY (setting_key)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }

        if (!$this->existingTableIsCompatible(
            'condor_platform_setting_audit',
            [
                'id' => ['bigint', 0, 'NO', 'auto_increment'],
                'setting_key' => ['varchar', 100, 'NO', ''],
                'action' => ['varchar', 16, 'NO', ''],
                'actor_id' => ['varchar', 80, 'NO', ''],
                'occurred_at' => ['datetime', 0, 'NO', ''],
            ],
            ['PRIMARY' => ['id'], 'idx_platform_setting_audit_key' => ['setting_key', 'occurred_at']],
        )) {
            $this->addSql(<<<'SQL'
                CREATE TABLE condor_platform_setting_audit (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    setting_key VARCHAR(100) NOT NULL,
                    action VARCHAR(16) NOT NULL,
                    actor_id VARCHAR(80) NOT NULL,
                    occurred_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    INDEX idx_platform_setting_audit_key (setting_key, occurred_at)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL);
        }
    }

    /** @param array<string, array{string, int, string, string}> $expectedColumns
     *  @param array<string, list<string>> $expectedIndexes
     */
    private function existingTableIsCompatible(
        string $table,
        array $expectedColumns,
        array $expectedIndexes,
    ): bool {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, EXTRA '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );
        if ($rows === []) {
            return false;
        }

        $columns = [];
        foreach ($rows as $row) {
            $type = (string) $row['DATA_TYPE'];
            $columns[(string) $row['COLUMN_NAME']] = [
                $type,
                $type === 'varchar' ? (int) $row['CHARACTER_MAXIMUM_LENGTH'] : 0,
                (string) $row['IS_NULLABLE'],
                (string) $row['EXTRA'],
            ];
        }
        ksort($columns);
        ksort($expectedColumns);
        if ($columns !== $expectedColumns) {
            throw new RuntimeException('Esquema existente de ajustes de plataforma incompatible: '.$table);
        }

        $engine = $this->connection->fetchOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );
        if (strtoupper((string) $engine) !== 'INNODB') {
            throw new RuntimeException('Motor de tabla de plataforma incompatible: '.$table);
        }

        $indexes = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE '
            .'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            .'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        ) as $index) {
            $name = (string) $index['INDEX_NAME'];
            $indexes[$name][] = (string) $index['COLUMN_NAME'];
            if ($name === 'PRIMARY' && (int) $index['NON_UNIQUE'] !== 0) {
                throw new RuntimeException('Clave primaria inválida: '.$table);
            }
        }
        foreach ($expectedIndexes as $name => $indexColumns) {
            if (($indexes[$name] ?? []) !== $indexColumns) {
                throw new RuntimeException('Índice de tabla de plataforma incompatible: '.$table.' '.$name);
            }
        }

        return true;
    }

    public function preDown(Schema $schema): void
    {
        // Doctrine executes addSql() after down() and before postDown():
        // hold this named lock across counts AND actual DDL, also in dry-run.
        if ((int) $this->connection->fetchOne("SELECT GET_LOCK('".self::SCHEMA_LOCK."', 10)") !== 1) {
            throw new IrreversibleMigration('No se obtuvo exclusión para revertir ajustes de plataforma.');
        }
        $this->schemaLockHeld = true;
    }

    public function down(Schema $schema): void
    {
        try {
            if ((int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_platform_integration_setting',
            ) > 0 || (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_platform_setting_audit',
            ) > 0) {
                throw new IrreversibleMigration(
                    'Ajustes o auditoría existentes: restaurar backup y obtener aprobación explícita.',
                );
            }

            $this->addSql('DROP TABLE condor_platform_setting_audit');
            $this->addSql('DROP TABLE condor_platform_integration_setting');
        } catch (Throwable $error) {
            $this->releaseSchemaLock();
            throw $error;
        }
    }

    public function postDown(Schema $schema): void
    {
        $this->releaseSchemaLock();
    }

    private function releaseSchemaLock(): void
    {
        if ($this->schemaLockHeld) {
            $this->schemaLockHeld = false;
            $this->connection->fetchOne("SELECT RELEASE_LOCK('".self::SCHEMA_LOCK."')");
        }
    }
}

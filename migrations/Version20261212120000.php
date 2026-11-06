<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1726: additive platform-scoped settings; never migrates real credentials. */
final class Version20261212120000 extends AbstractMigration // NOSONAR -- Doctrine naming
{
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

    public function down(Schema $schema): void
    {
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
    }
}

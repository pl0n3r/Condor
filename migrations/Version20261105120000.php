<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Only additive synthetic M10 tables. D-054 backup/allowlist/dry-run remains
 * mandatory at deploy; this migration never enables production endpoints.
 */
final class Version20261105120000 extends AbstractMigration // NOSONAR -- Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Persistencia y auditoría sintética de activos, aislada por empresa, sin datos reales.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_asset_record (
                tenant_ref VARCHAR(80) NOT NULL,
                asset_ref VARCHAR(80) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                state VARCHAR(32) NOT NULL,
                evidence_ref VARCHAR(80) DEFAULT NULL,
                warranty_until VARCHAR(10) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_ref, asset_ref),
                INDEX idx_asset_record_tenant_state (tenant_ref, state)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_asset_audit (
                event_id CHAR(32) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                asset_ref VARCHAR(80) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                event VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (event_id),
                INDEX idx_asset_audit_scope (tenant_ref, asset_ref, created_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible only while both tables are empty. Never delete fixture or live rows.
        $auditCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_asset_audit');
        $recordCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_asset_record');
        if ($auditCount !== 0 || $recordCount !== 0) {
            throw new IrreversibleMigration(
                'No borrar datos de activos, ni siquiera sintéticos, sin decisión y backup.',
            );
        }
        $this->addSql('DROP TABLE condor_asset_audit');
        $this->addSql('DROP TABLE condor_asset_record');
    }
}

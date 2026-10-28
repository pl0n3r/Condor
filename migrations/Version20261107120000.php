<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Additive M13 fixture-only schema; D-054 guards deployment and backups. */
final class Version20261106120000 extends AbstractMigration // NOSONAR -- Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'M13: calidads ficticios por tenant/empresa, versiones inmutables y auditoría.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_quality_record (
                id VARCHAR(26) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                quality_ref VARCHAR(80) NOT NULL,
                category VARCHAR(20) NOT NULL,
                state VARCHAR(20) NOT NULL,
                version INT UNSIGNED NOT NULL,
                previous_id VARCHAR(26) DEFAULT NULL,
                previous_state VARCHAR(20) DEFAULT NULL,
                audit_action VARCHAR(20) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                fingerprint VARCHAR(64) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_quality_scope_version (tenant_ref, company_ref, quality_ref, version),
                INDEX idx_quality_company_state (tenant_ref, company_ref, state),
                CONSTRAINT chk_quality_category CHECK (category IN ('no_conformidad', 'indicador', 'auditoria', 'accion')),
                CONSTRAINT chk_quality_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION')),
                CONSTRAINT chk_quality_action CHECK (audit_action IN ('CREADA', 'CAMBIO_EXPLICITO')),
                CONSTRAINT chk_quality_version CHECK (version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_quality_audit (
                event_id CHAR(32) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                quality_ref VARCHAR(80) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                event VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (event_id),
                INDEX idx_quality_audit_scope (tenant_ref, company_ref, created_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_quality_audit') !== 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_quality_record') !== 0,
            'Rollback denegado: existen registros o auditorías, incluso sintéticos.',
        );
        $this->addSql('DROP TABLE condor_quality_audit');
        $this->addSql('DROP TABLE condor_quality_record');
    }
}

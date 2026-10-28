<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additive pre-live fixture schema: no FKs to real tenants and no real data.
 * Reversal is permitted only while the table is empty.
 */
final class Version20261102120000 extends AbstractMigration // NOSONAR -- nombre Doctrine
{
    public function getDescription(): string
    {
        return 'M06: historial inmutable de cumplimiento sintético por empresa y actor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_compliance_record (
                id VARCHAR(26) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                obligation_ref VARCHAR(80) NOT NULL,
                evidence_ref VARCHAR(80) NOT NULL,
                status VARCHAR(20) NOT NULL,
                version INT UNSIGNED NOT NULL,
                previous_record_id VARCHAR(26) DEFAULT NULL,
                previous_status VARCHAR(20) DEFAULT NULL,
                audit_action VARCHAR(20) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                fingerprint CHAR(64) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_compliance_case_version
                    (tenant_ref, company_ref, obligation_ref, version),
                INDEX idx_compliance_tenant_company (tenant_ref, company_ref),
                INDEX idx_compliance_previous (tenant_ref, company_ref, previous_record_id),
                CONSTRAINT chk_compliance_status
                    CHECK (status IN ('PENDIENTE', 'EN_REVISION', 'OBSERVADA', 'CERRADA')),
                CONSTRAINT chk_compliance_audit_action
                    CHECK (audit_action IN ('CREADA', 'CAMBIO_EXPLICITO')),
                CONSTRAINT chk_compliance_version CHECK (version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_compliance_record',
        );
        $this->abortIf(
            $count > 0,
            'Rollback bloqueado: hay auditoría sintética; requiere revisión humana y backup.',
        );
        $this->addSql('DROP TABLE condor_compliance_record');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * D-054: additive-only schema. Never runs a migration, backfill or go-live.
 * Down intentionally fails closed rather than dropping payroll evidence.
 */
final class Version20261204120000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Nómina sintética por empresa, con auditoría append-only y sin activación real.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_payroll_record (
                tenant_ref VARCHAR(96) NOT NULL,
                legal_entity_ref VARCHAR(96) NOT NULL,
                record_ref VARCHAR(96) NOT NULL,
                employee_ref VARCHAR(96) NOT NULL,
                period_ref VARCHAR(96) NOT NULL,
                actor_ref VARCHAR(96) NOT NULL,
                amount_hundredths BIGINT NOT NULL,
                state VARCHAR(32) NOT NULL,
                created_at VARCHAR(32) NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                PRIMARY KEY (tenant_ref, legal_entity_ref, record_ref),
                INDEX idx_payroll_entity_state (tenant_ref, legal_entity_ref, state, created_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_payroll_audit (
                audit_ref CHAR(64) NOT NULL,
                tenant_ref VARCHAR(96) NOT NULL,
                legal_entity_ref VARCHAR(96) NOT NULL,
                record_ref VARCHAR(96) NOT NULL,
                actor_ref VARCHAR(96) NOT NULL,
                action VARCHAR(40) NOT NULL,
                occurred_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (audit_ref),
                INDEX idx_payroll_audit_scope (tenant_ref, legal_entity_ref, record_ref),
                CONSTRAINT FK_PAYROLL_AUDIT_RECORD
                    FOREIGN KEY (tenant_ref, legal_entity_ref, record_ref)
                    REFERENCES condor_payroll_record (tenant_ref, legal_entity_ref, record_ref)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Payroll records/audit cannot be dropped without explicit owner approval and backup.',
        );
    }
}

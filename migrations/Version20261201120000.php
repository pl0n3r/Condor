<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** M01: solo esquema aditivo de registros ficticios y eventos de auditoría. */
final class Version20261201120000 extends AbstractMigration // NOSONAR: nombre exigido por Doctrine
{
    public function getDescription(): string
    {
        return 'Almacenamiento offline de eventos M01, segregados por empresa.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE condor_employee_record (
    id VARCHAR(26) NOT NULL PRIMARY KEY,
    tenant_ref VARCHAR(80) NOT NULL,
    company_ref VARCHAR(80) NOT NULL,
    employee_ref VARCHAR(80) NOT NULL,
    actor_ref VARCHAR(80) NOT NULL,
    category VARCHAR(20) NOT NULL,
    state VARCHAR(20) NOT NULL,
    version INT UNSIGNED NOT NULL,
    previous_id VARCHAR(26) DEFAULT NULL,
    previous_state VARCHAR(20) DEFAULT NULL,
    audit_action VARCHAR(20) NOT NULL,
    occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    fingerprint VARCHAR(64) NOT NULL,
    CONSTRAINT chk_employee_version CHECK (version >= 1),
    CONSTRAINT chk_employee_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION')),
    CONSTRAINT chk_employee_action CHECK (audit_action IN ('CREADA', 'CAMBIO_EXPLICITO')),
    CONSTRAINT chk_employee_category CHECK (category IN ('vinculacion', 'novedad', 'vacacion', 'evaluacion')),
    UNIQUE INDEX uniq_employee_scope_version (tenant_ref, company_ref, employee_ref, version),
    INDEX idx_employee_company_state (tenant_ref, company_ref, state)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_employee_audit (
    event_id CHAR(32) PRIMARY KEY,
    tenant_ref VARCHAR(80) NOT NULL,
    company_ref VARCHAR(80) NOT NULL,
    employee_ref VARCHAR(80) NOT NULL,
    actor_ref VARCHAR(80) NOT NULL,
    event VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_employee_audit_scope (tenant_ref, company_ref, created_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $records = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_employee_record'
        );
        $audit = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_employee_audit'
        );
        $this->abortIf($records > 0 || $audit > 0, 'Rollback M01 prohibido con eventos existentes.');
        $this->addSql('DROP TABLE condor_employee_audit');
        $this->addSql('DROP TABLE condor_employee_record');
    }
}

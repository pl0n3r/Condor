<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Tabla nueva y aditiva para instrumentos de Procesos.
 * Una versión auditada nunca se reescribe ni se descarta durante rollback.
 */
final class Version20261106137000 extends AbstractMigration // NOSONAR
{
    private const TABLE = 'condor_operations_process_real_record';

    public function getDescription(): string
    {
        return 'Historial inmutable de Procesos por razón social y tenant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE condor_operations_process_real_record (
    id VARCHAR(26) NOT NULL,
    tenant_id VARCHAR(26) NOT NULL,
    company_id VARCHAR(26) NOT NULL,
    record_ref VARCHAR(64) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    state VARCHAR(20) NOT NULL,
    version INT NOT NULL,
    previous_id VARCHAR(26) DEFAULT NULL,
    actor_id VARCHAR(26) NOT NULL,
    occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_operations_process_company_ref_version (tenant_id, company_id, record_ref, version),
    INDEX ix_operations_process_scope_state (tenant_id, company_id, state),
    CONSTRAINT fk_operations_process_company
        FOREIGN KEY (tenant_id, company_id)
        REFERENCES condor_legal_entity (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT ck_operations_process_kind
        CHECK (kind IN ('PROCESO', 'PROCEDIMIENTO', 'FLUJO', 'CONTROL')),
    CONSTRAINT ck_operations_process_state
        CHECK (state IN ('PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION')),
    CONSTRAINT ck_operations_process_revision
        CHECK (version >= 1)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        // Doctrine ejecuta down() incluso con --dry-run; SQL directo destruiría datos.
        // Sin API de dry-run en AbstractMigration, la reversión automatizada se deniega.
        // Una eliminación controlada de una tabla demostrablemente vacía requiere
        // aprobación explícita, backup y operación manual separada.
        throw new IrreversibleMigration(
            'Rollback automático de '.self::TABLE.' denegado: requiere validación y aprobación manual.',
        );
    }
}

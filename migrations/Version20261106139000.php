<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Additive, append-only asset records. Never erases production data on rollback. */
final class Version20261106139000 extends AbstractMigration // NOSONAR
{
    private const TABLE = 'condor_asset_real_record';

    public function getDescription(): string
    {
        return 'Versiones auditadas de activos por tenant y razón social; solo datos sintéticos antes de live.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE condor_asset_real_record (
    id VARCHAR(26) NOT NULL,
    tenant_id VARCHAR(26) NOT NULL,
    company_id VARCHAR(26) NOT NULL,
    record_ref VARCHAR(64) NOT NULL,
    category VARCHAR(20) NOT NULL,
    state VARCHAR(24) NOT NULL,
    responsible_ref VARCHAR(64) DEFAULT NULL,
    evidence_ref VARCHAR(64) DEFAULT NULL,
    warranty_until DATE DEFAULT NULL,
    version INT NOT NULL,
    previous_id VARCHAR(26) DEFAULT NULL,
    actor_id VARCHAR(26) NOT NULL,
    occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_real_scope_ref_version (tenant_id, company_id, record_ref, version),
    INDEX ix_asset_real_scope_state (tenant_id, company_id, state),
    CONSTRAINT fk_asset_real_legal_company
        FOREIGN KEY (tenant_id, company_id)
        REFERENCES condor_legal_entity (tenant_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT ck_asset_real_category
        CHECK (category IN ('EQUIPO', 'MUEBLE', 'VEHICULO')),
    CONSTRAINT ck_asset_real_state
        CHECK (state IN ('PENDIENTE', 'ASIGNADO', 'EN_REVISION', 'BAJA_REQUIERE_REVISION')),
    CONSTRAINT ck_asset_real_version CHECK (version >= 1)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Rollback automático de '.self::TABLE.' denegado: exige backup, verificación de tabla vacía y autorización explícita.',
        );
    }
}

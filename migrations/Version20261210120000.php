<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1673: additive HR storage only; does not enable any endpoint or migrate live data. */
final class Version20261210120000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'M01 real RRHH: historial auditable con aislamiento tenant/razón social.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_hr_real_record (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                company_id VARCHAR(26) NOT NULL,
                record_ref VARCHAR(64) NOT NULL,
                kind VARCHAR(24) NOT NULL,
                collaborator_ref VARCHAR(64) NOT NULL,
                position_ref VARCHAR(64) NOT NULL,
                state VARCHAR(20) NOT NULL,
                version INT NOT NULL,
                previous_id VARCHAR(26) DEFAULT NULL,
                actor_id VARCHAR(26) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_hr_scope_version
                    (tenant_id, company_id, record_ref, version),
                INDEX idx_hr_company_state (tenant_id, company_id, state),
                CONSTRAINT FK_HR_COMPANY_SCOPE
                    FOREIGN KEY (tenant_id, company_id)
                    REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT chk_hr_state CHECK (state IN ('BORRADOR', 'EN_REVISION')),
                CONSTRAINT chk_hr_kind
                    CHECK (kind IN ('vinculacion', 'novedad', 'movimiento_interno')),
                CONSTRAINT chk_hr_version CHECK (version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    /** DDL is non-transactional in MariaDB; do not claim an atomic SQL rollback. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('LOCK TABLES condor_hr_real_record WRITE');
        try {
            if ((int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_hr_real_record',
            ) !== 0) {
                throw new IrreversibleMigration(
                    'RRHH contiene registros auditables: restaurar backup y obtener decisión humana.',
                );
            }
            // Execute while the exclusive lock is held, not queued after unlock.
            $this->connection->executeStatement('DROP TABLE condor_hr_real_record');
        } finally {
            $this->connection->executeStatement('UNLOCK TABLES');
        }
    }
}

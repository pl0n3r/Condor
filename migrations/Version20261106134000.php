<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Expand-only finance case history; no backfill, live flag, or payment execution. */
final class Version20261106134000 extends AbstractMigration // NOSONAR Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Historial inmutable de revisión SG-SST por tenant y razón social.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE condor_ohs_real_record ('
            .'id VARCHAR(26) NOT NULL PRIMARY KEY,'
            .'tenant_id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL,'
            .'record_ref VARCHAR(64) NOT NULL, kind VARCHAR(20) NOT NULL,'
            .'state VARCHAR(20) NOT NULL, version INT NOT NULL,'
            .'previous_id VARCHAR(26) DEFAULT NULL, actor_id VARCHAR(26) NOT NULL,'
            ."occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',"
            .'UNIQUE INDEX uniq_ohs_real_scope_version (tenant_id, company_id, record_ref, version),'
            .'INDEX idx_ohs_real_company_state (tenant_id, company_id, state),'
            .'CONSTRAINT FK_OHS_REAL_COMPANY_SCOPE FOREIGN KEY (tenant_id, company_id)'
            .' REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT,'
            ."CONSTRAINT chk_ohs_real_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION')),"
            ."CONSTRAINT chk_ohs_real_kind CHECK (kind IN ('INSPECCION', 'CONTROL', 'CAPACITACION', 'EMERGENCIA')),"
            .'CONSTRAINT chk_ohs_real_version CHECK (version >= 1)'
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
    }

    public function isTransactional(): bool { return false; }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('LOCK TABLES condor_ohs_real_record WRITE');
        try {
            if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_ohs_real_record') !== 0) {
                throw new IrreversibleMigration('SG-SST no vacío: backup y autorización externa requeridos.');
            }
            $this->connection->executeStatement('DROP TABLE condor_ohs_real_record');
        } finally {
            $this->connection->executeStatement('UNLOCK TABLES');
        }
    }
}

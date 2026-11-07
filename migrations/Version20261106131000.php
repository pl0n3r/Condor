<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1654: schema for versioned, pre-live contract events. No runtime activation. */
final class Version20261106131000 extends AbstractMigration // NOSONAR: Doctrine migration naming
{
    public function getDescription(): string
    {
        return 'Eventos de contratación por razón social con auditoría inmutable.';
    }

    public function up(Schema $schema): void
    {
        // Static SQL, no interpolated input. One event per version and tenant/company.
        $this->addSql(<<<'SQL'
CREATE TABLE condor_contract_real_record (
  id VARCHAR(26) NOT NULL PRIMARY KEY,
  tenant_id VARCHAR(26) NOT NULL,
  company_id VARCHAR(26) NOT NULL,
  record_ref VARCHAR(64) NOT NULL,
  worker_ref VARCHAR(64) NOT NULL,
  template_ref VARCHAR(64) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  state VARCHAR(20) NOT NULL,
  version INT NOT NULL,
  previous_id VARCHAR(26) DEFAULT NULL,
  actor_id VARCHAR(26) NOT NULL,
  occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  UNIQUE INDEX uniq_contract_scope_version (tenant_id, company_id, record_ref, version),
  INDEX idx_contract_company_state (tenant_id, company_id, state),
  CONSTRAINT FK_CONTRACT_COMPANY_SCOPE FOREIGN KEY (tenant_id, company_id)
    REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_contract_state CHECK (state IN ('REQUIERE_REVISION', 'EN_REVISION')),
  CONSTRAINT chk_contract_version CHECK (version >= 1)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
    }

    /** MariaDB DDL is not transactional: no claim of atomic schema rollback. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('LOCK TABLES condor_contract_real_record WRITE');
        try {
            if ((int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_contract_real_record',
            ) !== 0) {
                throw new IrreversibleMigration(
                    'Contratación con historial auditable: rollback exige decisión humana y backup.',
                );
            }
            $this->connection->executeStatement('DROP TABLE condor_contract_real_record');
        } finally {
            $this->connection->executeStatement('UNLOCK TABLES');
        }
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1654: additive Contracts storage only; does not enable any endpoint or migrate live data. */
final class Version20261106131000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Contratación persistente: versiones auditables por tenant y razón social.';
    }

    public function up(Schema $schema): void
    {
        // Build the stable, additive MariaDB DDL from declared columns.
        // No values come from requests; each identifier and constraint is fixed.
        $definition = [
            'id VARCHAR(26) NOT NULL',
            'tenant_id VARCHAR(26) NOT NULL',
            'company_id VARCHAR(26) NOT NULL',
            'record_ref VARCHAR(64) NOT NULL',
            'worker_ref VARCHAR(64) NOT NULL',
            'template_ref VARCHAR(64) NOT NULL',
            'kind VARCHAR(16) NOT NULL',
            'state VARCHAR(20) NOT NULL',
            'version INT NOT NULL',
            'previous_id VARCHAR(26) DEFAULT NULL',
            'actor_id VARCHAR(26) NOT NULL',
            "occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
            'PRIMARY KEY (id)',
            'UNIQUE INDEX uniq_contract_scope_version (tenant_id, company_id, record_ref, version)',
            'INDEX idx_contract_company_state (tenant_id, company_id, state)',
            'CONSTRAINT FK_CONTRACT_COMPANY_SCOPE FOREIGN KEY (tenant_id, company_id)'
                .' REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT',
            "CONSTRAINT chk_contract_state CHECK (state IN ('REQUIERE_REVISION', 'EN_REVISION'))",
            'CONSTRAINT chk_contract_version CHECK (version >= 1)',
        ];
        $this->addSql(
            'CREATE TABLE condor_contract_real_record ( '.implode(', ', $definition)
            .' ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
    }

    /** DDL is non-transactional in MariaDB; do not claim an atomic SQL rollback. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        $connection = $this->connection;
        $connection->executeStatement('LOCK TABLES condor_contract_real_record WRITE');
        try {
            $storedVersions = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM condor_contract_real_record',
            );
            if ($storedVersions > 0) {
                throw new IrreversibleMigration(
                    'Contratación contiene registros auditables: restaurar backup y obtener decisión humana.',
                );
            }
            // Keep the exclusive lock until the empty table has been dropped.
            $connection->executeStatement('DROP TABLE condor_contract_real_record');
        } finally {
            $connection->executeStatement('UNLOCK TABLES');
        }
    }
}

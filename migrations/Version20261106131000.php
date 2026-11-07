<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1654: expand-only structure for synthetic pre-live contract events. */
final class Version20261106131000 extends AbstractMigration // NOSONAR: Doctrine migration naming
{
    public function getDescription(): string
    {
        return 'Historial de contratación por empresa con control de versiones y auditoría.';
    }

    public function up(Schema $schema): void
    {
        // Stable, closed schema, independent from untrusted HTTP input.
        $identity = [
            'id VARCHAR(26) NOT NULL PRIMARY KEY',
            'tenant_id VARCHAR(26) NOT NULL',
            'company_id VARCHAR(26) NOT NULL',
            'record_ref VARCHAR(64) NOT NULL',
        ];
        $contract = [
            'worker_ref VARCHAR(64) NOT NULL',
            'template_ref VARCHAR(64) NOT NULL',
            'kind VARCHAR(16) NOT NULL',
            'state VARCHAR(20) NOT NULL',
        ];
        $audit = [
            'version INT NOT NULL',
            'previous_id VARCHAR(26) DEFAULT NULL',
            'actor_id VARCHAR(26) NOT NULL',
            "occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
        ];
        $integrity = [
            'UNIQUE INDEX uniq_contract_scope_version (tenant_id, company_id, record_ref, version)',
            'INDEX idx_contract_company_state (tenant_id, company_id, state)',
            'CONSTRAINT FK_CONTRACT_COMPANY_SCOPE FOREIGN KEY (tenant_id, company_id)'
                .' REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT',
            "CONSTRAINT chk_contract_state CHECK (state IN ('REQUIERE_REVISION', 'EN_REVISION'))",
            'CONSTRAINT chk_contract_version CHECK (version >= 1)',
        ];
        $definition = array_merge($identity, $contract, $audit, $integrity);
        $this->addSql(
            'CREATE TABLE condor_contract_real_record ('.implode(', ', $definition).')'
            .' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
    }

    /** Schema DDL in MariaDB cannot be rolled back atomically. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        $database = $this->connection;
        $database->executeStatement('LOCK TABLES condor_contract_real_record WRITE');
        try {
            $hasEvents = (int) $database->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM condor_contract_real_record LIMIT 1)',
            );
            if ($hasEvents !== 0) {
                throw new IrreversibleMigration(
                    'No se puede eliminar una tabla de contratación con historial: requiere autorización y backup.',
                );
            }
            // Execute DROP before releasing the exclusive metadata/write lock.
            $database->executeStatement('DROP TABLE condor_contract_real_record');
        } finally {
            $database->executeStatement('UNLOCK TABLES');
        }
    }
}

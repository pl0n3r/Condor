<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Additive, synthetic-only and tenant/company-scoped; no real HR data. */
final class Version20261206120000 extends AbstractMigration // NOSONAR Doctrine convention
{
    public function getDescription(): string
    {
        return 'Registros contractuales sintéticos inmutables por tenant y empresa, revisión humana obligatoria.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_contract_synthetic_record (
                id VARCHAR(32) NOT NULL,
                tenant_ref VARCHAR(64) NOT NULL,
                company_ref VARCHAR(64) NOT NULL,
                contract_ref VARCHAR(64) NOT NULL,
                worker_ref VARCHAR(64) NOT NULL,
                template_ref VARCHAR(64) NOT NULL,
                actor_ref VARCHAR(64) NOT NULL,
                state VARCHAR(32) NOT NULL,
                version INT NOT NULL,
                audit_action VARCHAR(16) NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_contract_synthetic_scope_version (
                    tenant_ref, company_ref, contract_ref, version
                ),
                INDEX idx_contract_synthetic_scope (
                    tenant_ref, company_ref, contract_ref, created_at
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_contract_synthetic_record'
        );
        if ($count > 0) {
            throw new IrreversibleMigration('No eliminar registros contractuales auditados.');
        }
        $this->addSql('DROP TABLE condor_contract_synthetic_record');
    }
}

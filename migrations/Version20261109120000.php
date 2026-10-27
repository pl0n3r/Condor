<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** New tables only. D-054 controls any real execution via backup and dry-run. */
final class Version20261109120000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'M16 casos sintéticos por tenant con auditoría inmutable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_customer_case_record (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                case_reference VARCHAR(96) NOT NULL,
                case_kind VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                version INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_ccr_tenant_reference (tenant_id, case_reference),
                UNIQUE INDEX uniq_ccr_tenant_id (tenant_id, id),
                CONSTRAINT FK_CCR_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_customer_case_audit (
                id VARCHAR(32) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                case_id VARCHAR(26) NOT NULL,
                actor_ref VARCHAR(64) NOT NULL,
                action VARCHAR(24) NOT NULL,
                status_from VARCHAR(20) DEFAULT NULL,
                status_to VARCHAR(20) DEFAULT NULL,
                recorded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_cca_case (tenant_id, case_id, recorded_at),
                CONSTRAINT FK_CCA_CASE FOREIGN KEY (tenant_id, case_id)
                    REFERENCES condor_customer_case_record (tenant_id, id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only an empty synthetic schema can be reverted without losing case
        // history. Non-empty schema is never removed by an automated rollback.
        $cases = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_customer_case_record');
        $events = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_customer_case_audit');
        if ($cases > 0 || $events > 0) {
            throw new IrreversibleMigration('Reversión denegada: casos o auditorías presentes; verificar backup.');
        }
        $this->addSql('DROP TABLE condor_customer_case_audit');
        $this->addSql('DROP TABLE condor_customer_case_record');
    }
}

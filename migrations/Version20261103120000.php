<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** M07 construction-only additive fixture table; rollback never deletes history. */
final class Version20261103120000 extends AbstractMigration // NOSONAR -- Doctrine migration naming
{
    public function getDescription(): string
    {
        return 'M07: registro financiero ficticio, versionado, auditable y aislado por empresa.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_finance_record (
                id VARCHAR(26) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                legal_entity_ref VARCHAR(80) NOT NULL,
                record_ref VARCHAR(80) NOT NULL,
                kind VARCHAR(20) NOT NULL,
                amount_decimal VARCHAR(16) NOT NULL,
                state VARCHAR(20) NOT NULL,
                version INT UNSIGNED NOT NULL,
                previous_id VARCHAR(26) DEFAULT NULL,
                previous_state VARCHAR(20) DEFAULT NULL,
                audit_action VARCHAR(20) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                fingerprint VARCHAR(64) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_finance_scope_version
                    (tenant_ref, legal_entity_ref, record_ref, version),
                INDEX idx_finance_scope_kind (tenant_ref, legal_entity_ref, kind),
                CONSTRAINT chk_finance_state CHECK (state IN ('BORRADOR', 'REQUIERE_REVISION')),
                CONSTRAINT chk_finance_kind CHECK (kind IN ('ENTRY', 'ACCOUNT', 'BANK', 'BUDGET', 'PERIOD')),
                CONSTRAINT chk_finance_audit CHECK (audit_action IN ('CREADA', 'CAMBIO_EXPLICITO')),
                CONSTRAINT chk_finance_version CHECK (version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_finance_record') !== 0,
            'Rollback denegado: auditoría financiera existente requiere decisión y respaldo.',
        );
        $this->addSql('DROP TABLE condor_finance_record');
    }
}

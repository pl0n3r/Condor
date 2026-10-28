<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** M08 construction-only additive schema; no deployment, backfill or real data. */
final class Version20261104120000 extends AbstractMigration // NOSONAR -- Doctrine naming
{
    public function getDescription(): string
    {
        return 'M08: historial documental sintético por empresa y auditoría de accesos.';
    }

    public function up(Schema $schema): void
    {
        // Separate DDL units keep document integrity and audit structures independently reviewable.
        $this->addSql($this->documentRecordSql());
        $this->addSql($this->documentAuditSql());
    }

    /** Only append new structures; never modify or backfill existing tenant data. */
    private function documentRecordSql(): string
    {
        return <<<'SQL'
            CREATE TABLE condor_document_record (
                id VARCHAR(26) NOT NULL,
                tenant_ref VARCHAR(96) NOT NULL,
                legal_entity_ref VARCHAR(96) NOT NULL,
                record_ref VARCHAR(96) NOT NULL,
                version_ref VARCHAR(96) NOT NULL,
                classification VARCHAR(16) NOT NULL,
                state VARCHAR(24) NOT NULL,
                version INT UNSIGNED NOT NULL,
                previous_id VARCHAR(26) DEFAULT NULL,
                actor_ref VARCHAR(96) NOT NULL,
                audit_action VARCHAR(24) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                fingerprint CHAR(64) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_document_company_version (tenant_ref, legal_entity_ref, record_ref, version),
                INDEX idx_document_company_state (tenant_ref, legal_entity_ref, state),
                CONSTRAINT chk_document_state CHECK (state IN ('BORRADOR', 'REQUIERE_REVISION')),
                CONSTRAINT chk_document_class CHECK (classification IN ('PUBLICA', 'INTERNA', 'RESERVADA')),
                CONSTRAINT chk_document_version CHECK (version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL;
    }

    /** Audit records reference their exact company-scoped document version. */
    private function documentAuditSql(): string
    {
        return <<<'SQL'
            CREATE TABLE condor_document_audit (
                audit_ref VARCHAR(26) NOT NULL,
                tenant_ref VARCHAR(96) NOT NULL,
                legal_entity_ref VARCHAR(96) NOT NULL,
                record_ref VARCHAR(96) NOT NULL,
                version INT UNSIGNED NOT NULL,
                actor_ref VARCHAR(96) NOT NULL,
                action VARCHAR(24) NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (audit_ref),
                INDEX idx_document_audit_scope (tenant_ref, legal_entity_ref, record_ref),
                CONSTRAINT FK_DOCUMENT_AUDIT_RECORD
                    FOREIGN KEY (tenant_ref, legal_entity_ref, record_ref, version)
                    REFERENCES condor_document_record (tenant_ref, legal_entity_ref, record_ref, version)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL;
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_document_audit') !== 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_document_record') !== 0,
            'No se permite eliminar expedientes/auditoría existentes sin autorización y respaldo.',
        );
        $this->addSql('DROP TABLE condor_document_audit');
        $this->addSql('DROP TABLE condor_document_record');
    }
}

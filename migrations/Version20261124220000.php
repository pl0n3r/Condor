<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Additive, synthetic-only branch association for M08; historical records stay untouched. */
final class Version20261124220000 extends AbstractMigration // NOSONAR -- Doctrine naming
{
    public function getDescription(): string
    {
        return 'GEST: vínculo documental por sede, sin modificar historial ni backfill.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_document_branch_link (
                tenant_ref VARCHAR(96) NOT NULL,
                legal_entity_ref VARCHAR(96) NOT NULL,
                branch_ref VARCHAR(96) NOT NULL,
                external_ref VARCHAR(96) NOT NULL,
                record_ref VARCHAR(96) NOT NULL,
                initial_version INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (tenant_ref, legal_entity_ref, branch_ref, external_ref),
                UNIQUE INDEX uniq_document_branch_internal (tenant_ref, legal_entity_ref, record_ref),
                CONSTRAINT chk_document_branch_initial CHECK (initial_version = 1),
                CONSTRAINT fk_document_branch_initial
                    FOREIGN KEY (tenant_ref, legal_entity_ref, record_ref, initial_version)
                    REFERENCES condor_document_record (tenant_ref, legal_entity_ref, record_ref, version)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_document_branch_link') !== 0,
            'No eliminar vínculos de sedes sin autorización y backup.',
        );
        $this->addSql('DROP TABLE condor_document_branch_link');
    }
}

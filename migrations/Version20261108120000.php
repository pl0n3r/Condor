<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** M15 fixture-only tables; D-054 controls execution, backups and production gating. */
final class Version20261108120000 extends AbstractMigration // NOSONAR -- Doctrine class naming convention
{
    public function getDescription(): string
    {
        return 'Gobierno corporativo sintético: instrumentos versionados y journal de acceso por empresa.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_governance_entry (
                entry_id VARCHAR(26) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                governance_ref VARCHAR(80) NOT NULL,
                category VARCHAR(20) NOT NULL,
                state VARCHAR(20) NOT NULL,
                revision INT UNSIGNED NOT NULL,
                prior_id VARCHAR(26) DEFAULT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                action VARCHAR(20) NOT NULL,
                recorded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                fingerprint VARCHAR(64) NOT NULL,
                PRIMARY KEY (entry_id),
                UNIQUE INDEX uniq_gov_revision (tenant_ref, company_ref, governance_ref, revision),
                INDEX idx_gov_company_state (tenant_ref, company_ref, state),
                CONSTRAINT chk_gov_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION')),
                CONSTRAINT chk_gov_action CHECK (action IN ('CREADA', 'CAMBIO_EXPLICITO')),
                CONSTRAINT chk_gov_rev CHECK (revision >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_governance_journal (
                journal_id CHAR(32) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                governance_ref VARCHAR(80) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                event_kind VARCHAR(32) NOT NULL,
                written_at DATETIME NOT NULL,
                PRIMARY KEY (journal_id),
                INDEX idx_gov_journal_company (tenant_ref, company_ref, written_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_governance_entry') !== 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_governance_journal') !== 0,
            'Reversión denegada: existe historial o auditoría; verificar un backup antes de intervenir.',
        );
        $this->addSql('DROP TABLE condor_governance_journal');
        $this->addSql('DROP TABLE condor_governance_entry');
    }
}

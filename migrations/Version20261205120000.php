<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** M05: two additive tables for synthetic social_security cases and immutable events. */
final class Version20261205120000 extends AbstractMigration // NOSONAR -- Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Seguridad social M05: registros versionados por empresa y trazas sin datos reales.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_social_security_record (
                id VARCHAR(26) NOT NULL PRIMARY KEY,
                tenant_ref VARCHAR(80) NOT NULL, company_ref VARCHAR(80) NOT NULL,
                social_security_ref VARCHAR(80) NOT NULL, category VARCHAR(20) NOT NULL,
                state VARCHAR(20) NOT NULL, version INT UNSIGNED NOT NULL,
                previous_id VARCHAR(26) DEFAULT NULL, previous_state VARCHAR(20) DEFAULT NULL,
                audit_action VARCHAR(20) NOT NULL, actor_ref VARCHAR(80) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                fingerprint VARCHAR(64) NOT NULL,
                UNIQUE INDEX uniq_social_security_scope_version (tenant_ref, company_ref, social_security_ref, version),
                INDEX idx_social_security_company_state (tenant_ref, company_ref, state),
                CONSTRAINT chk_social_security_category CHECK (category IN ('afiliacion', 'aporte', 'novedad', 'soporte')),
                CONSTRAINT chk_social_security_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION')),
                CONSTRAINT chk_social_security_action CHECK (audit_action IN ('CREADA', 'CAMBIO_EXPLICITO')),
                CONSTRAINT chk_social_security_version CHECK (version >= 1)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_social_security_audit (
                event_id CHAR(32) NOT NULL PRIMARY KEY,
                tenant_ref VARCHAR(80) NOT NULL, company_ref VARCHAR(80) NOT NULL,
                social_security_ref VARCHAR(80) NOT NULL, actor_ref VARCHAR(80) NOT NULL,
                event VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL,
                INDEX idx_social_security_audit_scope (tenant_ref, company_ref, created_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_social_security_record') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_social_security_audit') > 0,
            'No se permite revertir Seguridad social mientras existan registros o eventos sintéticos.',
        );
        $this->addSql('DROP TABLE condor_social_security_audit');
        $this->addSql('DROP TABLE condor_social_security_record');
    }
}

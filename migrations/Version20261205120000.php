<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M05: isolated append-only synthetic social security history and audit events.
 * No customer data, operator PILA calls, external providers, or production side effects.
 */
final class Version20261205120000 extends AbstractMigration // NOSONAR -- Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'M05: historial sintético por tenant y empresa, con auditoría append-only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_social_security_record (
                id VARCHAR(26) NOT NULL PRIMARY KEY,
                social_security_ref VARCHAR(80) NOT NULL,
                version INT UNSIGNED NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                state VARCHAR(20) NOT NULL,
                category VARCHAR(20) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                audit_action VARCHAR(20) NOT NULL,
                previous_state VARCHAR(20) DEFAULT NULL,
                previous_id VARCHAR(26) DEFAULT NULL,
                CONSTRAINT chk_social_security_version CHECK (version >= 1),
                CONSTRAINT chk_social_security_action CHECK (audit_action IN ('CREADA', 'CAMBIO_EXPLICITO')),
                CONSTRAINT chk_social_security_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION')),
                CONSTRAINT chk_social_security_category CHECK (category IN ('afiliacion', 'aporte', 'novedad', 'soporte')),
                INDEX idx_social_security_company_state (tenant_ref, company_ref, state),
                UNIQUE INDEX uniq_social_security_scope_version (tenant_ref, company_ref, social_security_ref, version)
            ) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_social_security_audit (
                event_id CHAR(32) NOT NULL PRIMARY KEY,
                event VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL,
                social_security_ref VARCHAR(80) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                company_ref VARCHAR(80) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                INDEX idx_social_security_audit_scope (tenant_ref, company_ref, created_at)
            ) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $records = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_social_security_record',
        );
        $events = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_social_security_audit',
        );
        $this->abortIf(
            $records !== 0 || $events !== 0,
            'Rollback M05 denegado: hay registros o eventos de seguridad social.',
        );

        $this->addSql('DROP TABLE condor_social_security_audit');
        $this->addSql('DROP TABLE condor_social_security_record');
    }
}

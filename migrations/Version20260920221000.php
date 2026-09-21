<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920221000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade incidentes de error y enlaces diagnósticos temporales sanitizados.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_error_incident (
                id VARCHAR(26) NOT NULL,
                request_id VARCHAR(26) NOT NULL,
                status SMALLINT NOT NULL,
                method VARCHAR(12) NOT NULL,
                route_name VARCHAR(190) DEFAULT NULL,
                exception_class VARCHAR(255) NOT NULL,
                message VARCHAR(1200) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                version VARCHAR(32) NOT NULL,
                release_sha VARCHAR(40) NOT NULL,
                trace JSON NOT NULL,
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_error_incident_occurred (occurred_at),
                INDEX idx_error_incident_fingerprint (fingerprint),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_diagnostic_share (
                id VARCHAR(26) NOT NULL,
                incident_id VARCHAR(26) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_DIAGNOSTIC_SHARE_INCIDENT (incident_id),
                INDEX idx_diagnostic_share_expires (expires_at),
                UNIQUE INDEX UNIQ_DIAGNOSTIC_SHARE_TOKEN_HASH (token_hash),
                PRIMARY KEY(id),
                CONSTRAINT FK_DIAGNOSTIC_SHARE_INCIDENT FOREIGN KEY (incident_id)
                    REFERENCES condor_error_incident (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_diagnostic_share');
        $this->addSql('DROP TABLE condor_error_incident');
    }
}

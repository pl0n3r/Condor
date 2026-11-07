<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1656: expand-only persistence, no activation or data migration. */
final class Version20261106132000 extends AbstractMigration // NOSONAR Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Historial de seguridad social por tenant y razón social.';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            'id VARCHAR(26) NOT NULL PRIMARY KEY',
            'tenant_id VARCHAR(26) NOT NULL', 'company_id VARCHAR(26) NOT NULL',
            'record_ref VARCHAR(64) NOT NULL', 'category VARCHAR(20) NOT NULL',
            'state VARCHAR(20) NOT NULL', 'version INT NOT NULL',
            'previous_id VARCHAR(26) DEFAULT NULL', 'actor_id VARCHAR(26) NOT NULL',
            "occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
            'UNIQUE INDEX uniq_social_real_scope_version (tenant_id, company_id, record_ref, version)',
            'INDEX idx_social_real_company_state (tenant_id, company_id, state)',
            'CONSTRAINT FK_SOCIAL_REAL_COMPANY_SCOPE FOREIGN KEY (tenant_id, company_id)'
                .' REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT',
            "CONSTRAINT chk_social_real_state CHECK (state IN ('PENDIENTE', 'REQUIERE_REVISION'))",
            "CONSTRAINT chk_social_real_category CHECK (category IN ('afiliacion', 'aporte', 'novedad', 'soporte'))",
            'CONSTRAINT chk_social_real_version CHECK (version >= 1)',
        ];
        $this->addSql(
            'CREATE TABLE condor_social_security_real_record ('.implode(', ', $columns).')'
            .' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
    }

    public function isTransactional(): bool { return false; }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('LOCK TABLES condor_social_security_real_record WRITE');
        try {
            if ((int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_social_security_real_record',
            ) !== 0) {
                throw new IrreversibleMigration('Historial social no vacío: autorización y backup requeridos.');
            }
            $this->connection->executeStatement('DROP TABLE condor_social_security_real_record');
        } finally {
            $this->connection->executeStatement('UNLOCK TABLES');
        }
    }
}

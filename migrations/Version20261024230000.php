<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Migración estrictamente aditiva. D-054 exige dry-run y backup antes de desplegar. */
final class Version20261024230000 extends AbstractMigration // NOSONAR -- convención Doctrine
{
    public function getDescription(): string
    {
        return 'Persistencia tenant-safe de definiciones versionadas y resultados de workflows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_workflow_definition (
                tenant_id VARCHAR(26) NOT NULL,
                workflow_id VARCHAR(128) NOT NULL,
                version INT UNSIGNED NOT NULL,
                trigger_name VARCHAR(128) NOT NULL,
                conditions_json LONGTEXT NOT NULL,
                actions_json LONGTEXT NOT NULL,
                is_active TINYINT(1) NOT NULL,
                created_by_user_id VARCHAR(26) NOT NULL,
                updated_by_user_id VARCHAR(26) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (tenant_id, workflow_id, version),
                INDEX idx_cwd_active (tenant_id, workflow_id, is_active),
                CONSTRAINT FK_CWD_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_workflow_execution (
                id VARCHAR(32) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                workflow_id VARCHAR(128) NOT NULL,
                definition_version INT UNSIGNED NOT NULL,
                actor_user_id VARCHAR(26) NOT NULL,
                result VARCHAR(16) NOT NULL,
                reason VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_cwe_tenant_workflow_created (tenant_id, workflow_id, created_at),
                CONSTRAINT FK_CWE_DEFINITION FOREIGN KEY (tenant_id, workflow_id, definition_version)
                    REFERENCES condor_workflow_definition (tenant_id, workflow_id, version)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_CWE_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Un DROP borraría auditorías: requiere autorización y backup.');
    }
}

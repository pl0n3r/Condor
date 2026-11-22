<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1943: additive per-tenant/per-user module overrides; no grants by default. */
final class Version20261222120000 extends AbstractMigration // NOSONAR -- Doctrine naming
{
    public function getDescription(): string
    {
        return 'Overrides de módulos por empresa y usuario, con procedencia del actor.';
    }

    public function isTransactional(): bool
    {
        return false; // MariaDB DDL commits independently.
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS condor_tenant_module (
                tenant_id VARCHAR(26) NOT NULL,
                module_key VARCHAR(80) NOT NULL,
                enabled TINYINT(1) NOT NULL,
                updated_by VARCHAR(26) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_id, module_key),
                CONSTRAINT fk_tenant_module_tenant FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS condor_user_module (
                tenant_id VARCHAR(26) NOT NULL,
                user_id VARCHAR(26) NOT NULL,
                module_key VARCHAR(80) NOT NULL,
                enabled TINYINT(1) NOT NULL,
                updated_by VARCHAR(26) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_id, user_id, module_key),
                INDEX idx_user_module_user (user_id),
                CONSTRAINT fk_user_module_tenant FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_module_user FOREIGN KEY (user_id)
                    REFERENCES condor_user (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Never silently drop operator choices or their audit history.
        throw new IrreversibleMigration(
            'La reversión de módulos requiere auditoría, backup y aprobación explícita.',
        );
    }
}

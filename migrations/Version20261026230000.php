<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Additive-only SEO settings. Never enabled by migrating; D-054 governs
 * execution with preflight, backup, allowlist and rollback readiness.
 */
final class Version20261026230000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Ajustes offline IndexNow/GTM por tenant/sede/canal, desactivados por defecto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_seo_settings (
                tenant_id VARCHAR(26) NOT NULL,
                branch_id VARCHAR(26) NOT NULL,
                channel_id VARCHAR(64) NOT NULL,
                indexnow_enabled TINYINT(1) NOT NULL DEFAULT 0,
                gtm_container_id VARCHAR(64) DEFAULT NULL,
                gtm_enabled TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_id, branch_id, channel_id),
                CONSTRAINT FK_CSS_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_CSS_BRANCH_TENANT FOREIGN KEY (tenant_id, branch_id)
                    REFERENCES condor_branch (tenant_id, id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Eliminar ajustes SEO puede destruir configuraciones; requiere decisión explícita.',
        );
    }
}

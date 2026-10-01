<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001171500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'CMS V1 tenant-scoped: temas, páginas y bloques con tipos fail-closed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_cms_theme (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                theme_key VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                created_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_cms_theme_tenant_key (tenant_id, theme_key),
                INDEX IDX_CMS_THEME_TENANT (tenant_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_CMS_THEME_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_cms_page (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                theme_id VARCHAR(26) NOT NULL,
                slug VARCHAR(180) NOT NULL,
                title VARCHAR(200) NOT NULL,
                created_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_cms_page_tenant_slug (tenant_id, slug),
                INDEX IDX_CMS_PAGE_TENANT (tenant_id),
                INDEX IDX_CMS_PAGE_THEME (theme_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_CMS_PAGE_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_CMS_PAGE_THEME FOREIGN KEY (theme_id)
                    REFERENCES condor_cms_theme (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_cms_block (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                page_id VARCHAR(26) NOT NULL,
                block_type VARCHAR(32) NOT NULL,
                payload JSON NOT NULL,
                sort_order INT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_cms_block_tenant_page_order (tenant_id, page_id, sort_order),
                INDEX IDX_CMS_BLOCK_TENANT (tenant_id),
                INDEX IDX_CMS_BLOCK_PAGE (page_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_CMS_BLOCK_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_CMS_BLOCK_PAGE FOREIGN KEY (page_id)
                    REFERENCES condor_cms_page (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $persisted = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM condor_cms_theme)
                + (SELECT COUNT(*) FROM condor_cms_page)
                + (SELECT COUNT(*) FROM condor_cms_block)
            SQL);

        $this->abortIf(
            $persisted > 0,
            'Rollback bloqueado: existe contenido CMS persistido.',
        );

        $this->addSql('DROP TABLE condor_cms_block');
        $this->addSql('DROP TABLE condor_cms_page');
        $this->addSql('DROP TABLE condor_cms_theme');
    }
}

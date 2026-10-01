<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001180500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'CMS V1 admin: estado draft/published y fecha de publicación.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE condor_cms_page
                ADD status VARCHAR(16) DEFAULT 'draft' NOT NULL,
                ADD published_at DATETIME(6) DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD CONSTRAINT CHK_CMS_PAGE_STATUS
                    CHECK (status IN ('draft', 'published'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $published = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM condor_cms_page WHERE status = 'published' OR published_at IS NOT NULL",
        );
        $this->abortIf(
            $published > 0,
            'Rollback bloqueado: existen páginas CMS publicadas.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE condor_cms_page
                DROP CONSTRAINT CHK_CMS_PAGE_STATUS,
                DROP status,
                DROP published_at
            SQL);
    }
}

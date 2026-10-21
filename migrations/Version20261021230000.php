<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Additive public product taxonomy. Deployment still requires D-054 checks and backup. */
final class Version20261021230000 extends AbstractMigration // NOSONAR -- nombre Doctrine
{
    public function getDescription(): string
    {
        return 'Taxonomía pública por tenant; productos existentes conservan category_id NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_product_category (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_product_category_tenant_slug (tenant_id, slug),
                UNIQUE INDEX uniq_product_category_tenant_id (tenant_id, id),
                CONSTRAINT FK_CPC_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE condor_product ADD category_id VARCHAR(26) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_product_category ON condor_product (tenant_id, category_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE condor_product
                ADD CONSTRAINT FK_CP_CATEGORY_TENANT
                FOREIGN KEY (tenant_id, category_id)
                REFERENCES condor_product_category (tenant_id, id)
                ON DELETE RESTRICT
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Retirar categorías puede destruir datos; requiere decisión y plan de reversión.');
    }
}

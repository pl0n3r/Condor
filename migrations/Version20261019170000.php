<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019170000 extends AbstractMigration // NOSONAR -- nombre Doctrine
{
    public function getDescription(): string
    {
        return 'Banco de fotos tenant-scoped de catálogo, sin alterar fotos ni productos previos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_product_media (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                product_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) DEFAULT NULL,
                file_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(40) NOT NULL,
                file_size INT NOT NULL,
                sort_order INT NOT NULL,
                is_primary TINYINT(1) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_product_media_public (tenant_id, product_id, active, sort_order),
                INDEX idx_product_media_variant (tenant_id, variant_id),
                INDEX idx_product_media_product (product_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_CPM_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant(id) ON DELETE CASCADE,
                CONSTRAINT FK_CPM_PRODUCT FOREIGN KEY (tenant_id, product_id)
                    REFERENCES condor_product(tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT FK_CPM_VARIANT FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant(tenant_id, id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_product_media');
    }
}

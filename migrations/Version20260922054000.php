<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922054000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Slice 3: catálogo tenant-owned de productos y variantes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_product (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_PRODUCT_TENANT (tenant_id),
                UNIQUE INDEX uniq_product_tenant_slug (tenant_id, slug),
                UNIQUE INDEX uniq_product_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCT_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_product_variant (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                product_id VARCHAR(26) NOT NULL,
                sku VARCHAR(120) NOT NULL,
                name VARCHAR(160) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_VARIANT_TENANT (tenant_id),
                INDEX IDX_VARIANT_PRODUCT (product_id),
                UNIQUE INDEX uniq_variant_tenant_sku (tenant_id, sku),
                UNIQUE INDEX uniq_variant_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_VARIANT_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_VARIANT_PRODUCT
                    FOREIGN KEY (product_id) REFERENCES condor_product (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_VARIANT_PRODUCT_TENANT
                    FOREIGN KEY (tenant_id, product_id)
                    REFERENCES condor_product (tenant_id, id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $variantCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_product_variant',
        );
        $productCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_product',
        );

        $this->abortIf(
            $variantCount > 0 || $productCount > 0,
            'Rollback bloqueado: existen productos o variantes reales. '
            .'La eliminación requiere una transición de datos explícitamente autorizada.',
        );

        $this->addSql('DROP TABLE condor_product_variant');
        $this->addSql('DROP TABLE condor_product');
    }
}

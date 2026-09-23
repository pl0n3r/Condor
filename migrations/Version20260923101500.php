<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923101500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Slice 5: clientes, categorías comerciales, listas y precios por variante.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_category (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_COMM_CATEGORY_TENANT (tenant_id),
                UNIQUE INDEX uniq_commercial_category_tenant_slug (tenant_id, slug),
                UNIQUE INDEX uniq_commercial_category_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_COMM_CATEGORY_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_customer (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                commercial_category_id VARCHAR(26) DEFAULT NULL,
                name VARCHAR(180) NOT NULL,
                email VARCHAR(254) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_CUSTOMER_TENANT (tenant_id),
                INDEX IDX_CUSTOMER_CATEGORY (commercial_category_id),
                UNIQUE INDEX uniq_customer_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_CUSTOMER_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_CUSTOMER_CATEGORY
                    FOREIGN KEY (commercial_category_id)
                    REFERENCES condor_commercial_category (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_CUSTOMER_CATEGORY_SCOPE
                    FOREIGN KEY (tenant_id, commercial_category_id)
                    REFERENCES condor_commercial_category (tenant_id, id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_price_list (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_PRICE_LIST_TENANT (tenant_id),
                UNIQUE INDEX uniq_price_list_tenant_slug (tenant_id, slug),
                UNIQUE INDEX uniq_price_list_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRICE_LIST_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_variant_price (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                price_list_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                amount_minor BIGINT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_VARIANT_PRICE_TENANT (tenant_id),
                INDEX IDX_VARIANT_PRICE_LIST (price_list_id),
                INDEX IDX_VARIANT_PRICE_VARIANT (variant_id),
                UNIQUE INDEX uniq_variant_price_scope (tenant_id, price_list_id, variant_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_VARIANT_PRICE_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_VARIANT_PRICE_LIST
                    FOREIGN KEY (price_list_id) REFERENCES condor_price_list (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_VARIANT_PRICE_LIST_SCOPE
                    FOREIGN KEY (tenant_id, price_list_id)
                    REFERENCES condor_price_list (tenant_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_VARIANT_PRICE_VARIANT
                    FOREIGN KEY (variant_id) REFERENCES condor_product_variant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_VARIANT_PRICE_VARIANT_SCOPE
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT CHK_VARIANT_PRICE_AMOUNT
                    CHECK (amount_minor >= 0)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $customerCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_customer');
        $categoryCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_commercial_category');
        $priceListCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_price_list');
        $variantPriceCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_variant_price');

        $this->abortIf(
            $customerCount > 0 || $categoryCount > 0 || $priceListCount > 0 || $variantPriceCount > 0,
            'Rollback bloqueado: existen clientes o configuración comercial real.',
        );

        $this->addSql('DROP TABLE condor_variant_price');
        $this->addSql('DROP TABLE condor_customer');
        $this->addSql('DROP TABLE condor_price_list');
        $this->addSql('DROP TABLE condor_commercial_category');
    }
}

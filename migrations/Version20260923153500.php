<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923153500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Slice 6: canal comercial con fuente de inventario y lista de precios efectivas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_sales_channel (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                inventory_source_id VARCHAR(26) NOT NULL,
                price_list_id VARCHAR(26) NOT NULL,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                type VARCHAR(20) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_SALES_CHANNEL_TENANT (tenant_id),
                INDEX IDX_SALES_CHANNEL_LEGAL (legal_entity_id),
                INDEX IDX_SALES_CHANNEL_SOURCE (inventory_source_id),
                INDEX IDX_SALES_CHANNEL_PRICE_LIST (price_list_id),
                UNIQUE INDEX uniq_sales_channel_tenant_slug (tenant_id, slug),
                UNIQUE INDEX uniq_sales_channel_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_sales_channel_tenant_type (tenant_id, type),
                PRIMARY KEY(id),
                CONSTRAINT FK_SALES_CHANNEL_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_SALES_CHANNEL_LEGAL
                    FOREIGN KEY (legal_entity_id) REFERENCES condor_legal_entity (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_SALES_CHANNEL_LEGAL_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id)
                    REFERENCES condor_legal_entity (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_SALES_CHANNEL_SOURCE
                    FOREIGN KEY (inventory_source_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_SALES_CHANNEL_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, inventory_source_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_SALES_CHANNEL_PRICE_LIST
                    FOREIGN KEY (price_list_id) REFERENCES condor_price_list (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_SALES_CHANNEL_PRICE_LIST_SCOPE
                    FOREIGN KEY (tenant_id, price_list_id)
                    REFERENCES condor_price_list (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_SALES_CHANNEL_TYPE
                    CHECK (type IN ('ecommerce'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $channelCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_sales_channel',
        );

        $this->abortIf(
            $channelCount > 0,
            'Rollback bloqueado: existen canales comerciales configurados.',
        );

        $this->addSql('DROP TABLE condor_sales_channel');
    }
}

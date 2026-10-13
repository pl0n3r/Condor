<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012121000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Compras Lite V1: órdenes, líneas y recepción idempotente por tenant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_purchasing_order (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                supplier_id VARCHAR(26) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                receipt_key VARCHAR(80) DEFAULT NULL,
                receipt_source_id VARCHAR(26) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                received_at DATETIME DEFAULT NULL,
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_purchasing_order_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_purchasing_order_tenant_receipt (tenant_id, receipt_key),
                INDEX idx_purchasing_order_supplier (tenant_id, supplier_id),
                CONSTRAINT FK_PURCHASING_ORDER_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_PURCHASING_ORDER_SUPPLIER
                    FOREIGN KEY (tenant_id, supplier_id)
                    REFERENCES condor_purchasing_supplier (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT CHK_PURCHASING_ORDER_STATUS
                    CHECK (status IN ('draft', 'received'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_purchasing_order_line (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                order_id VARCHAR(26) NOT NULL,
                material_id VARCHAR(26) NOT NULL,
                quantity DECIMAL(19,6) NOT NULL,
                unit_cost DECIMAL(19,4) NOT NULL,
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_purchasing_order_line_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_purchasing_order_line_material (tenant_id, order_id, material_id),
                INDEX idx_purchasing_order_line_material (tenant_id, material_id),
                CONSTRAINT FK_PURCHASING_LINE_ORDER
                    FOREIGN KEY (tenant_id, order_id)
                    REFERENCES condor_purchasing_order (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT FK_PURCHASING_LINE_MATERIAL
                    FOREIGN KEY (tenant_id, material_id)
                    REFERENCES condor_production_material (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT CHK_PURCHASING_LINE_QUANTITY CHECK (quantity > 0),
                CONSTRAINT CHK_PURCHASING_LINE_COST CHECK (unit_cost >= 0)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $orders = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_purchasing_order');
        $lines = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_purchasing_order_line');
        $this->abortIf($orders > 0 || $lines > 0, 'Rollback bloqueado: existen órdenes o líneas de compra.');
        $this->addSql('DROP TABLE condor_purchasing_order_line');
        $this->addSql('DROP TABLE condor_purchasing_order');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004153000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Producción Lite V1: órdenes, ledger decimal de materiales y completion atómica.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_production_order (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                bom_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                source_id VARCHAR(26) NOT NULL,
                target_quantity INT UNSIGNED NOT NULL,
                completed_quantity INT UNSIGNED DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                completion_idempotency_key VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                INDEX IDX_PRODUCTION_ORDER_TENANT (tenant_id),
                INDEX IDX_PRODUCTION_ORDER_BOM (bom_id),
                INDEX IDX_PRODUCTION_ORDER_VARIANT (variant_id),
                INDEX IDX_PRODUCTION_ORDER_SOURCE (source_id),
                UNIQUE INDEX uniq_production_order_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_production_order_tenant_completion_key
                    (tenant_id, completion_idempotency_key),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCTION_ORDER_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_ORDER_BOM
                    FOREIGN KEY (bom_id) REFERENCES condor_production_bom (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_ORDER_BOM_SCOPE
                    FOREIGN KEY (tenant_id, bom_id)
                    REFERENCES condor_production_bom (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_ORDER_VARIANT
                    FOREIGN KEY (variant_id) REFERENCES condor_product_variant (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_ORDER_VARIANT_SCOPE
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_ORDER_SOURCE
                    FOREIGN KEY (source_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_ORDER_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, source_id)
                    REFERENCES condor_inventory_source (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_PRODUCTION_ORDER_TARGET
                    CHECK (target_quantity > 0),
                CONSTRAINT CHK_PRODUCTION_ORDER_COMPLETED
                    CHECK (completed_quantity IS NULL OR completed_quantity > 0),
                CONSTRAINT CHK_PRODUCTION_ORDER_STATUS
                    CHECK (status IN ('draft', 'completed'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_production_material_balance (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                source_id VARCHAR(26) NOT NULL,
                material_id VARCHAR(26) NOT NULL,
                quantity DECIMAL(19,6) NOT NULL DEFAULT 0,
                version INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_PRODUCTION_MATERIAL_BALANCE_TENANT (tenant_id),
                INDEX IDX_PRODUCTION_MATERIAL_BALANCE_SOURCE (source_id),
                INDEX IDX_PRODUCTION_MATERIAL_BALANCE_MATERIAL (material_id),
                UNIQUE INDEX uniq_production_material_balance_scope
                    (tenant_id, source_id, material_id),
                UNIQUE INDEX uniq_production_material_balance_tenant_id
                    (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCTION_MATERIAL_BALANCE_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_MATERIAL_BALANCE_SOURCE
                    FOREIGN KEY (source_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_MATERIAL_BALANCE_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, source_id)
                    REFERENCES condor_inventory_source (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_MATERIAL_BALANCE_MATERIAL
                    FOREIGN KEY (material_id) REFERENCES condor_production_material (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_MATERIAL_BALANCE_MATERIAL_SCOPE
                    FOREIGN KEY (tenant_id, material_id)
                    REFERENCES condor_production_material (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_PRODUCTION_MATERIAL_BALANCE_QUANTITY
                    CHECK (quantity >= 0)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_production_material_movement (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                source_id VARCHAR(26) NOT NULL,
                material_id VARCHAR(26) NOT NULL,
                type VARCHAR(30) NOT NULL,
                delta DECIMAL(19,6) NOT NULL,
                balance_after DECIMAL(19,6) NOT NULL,
                actor_user_id VARCHAR(26) DEFAULT NULL,
                idempotency_key VARCHAR(120) DEFAULT NULL,
                context JSON NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_PRODUCTION_MATERIAL_MOVEMENT_TENANT (tenant_id),
                INDEX IDX_PRODUCTION_MATERIAL_MOVEMENT_SOURCE (source_id),
                INDEX IDX_PRODUCTION_MATERIAL_MOVEMENT_MATERIAL (material_id),
                INDEX idx_production_material_movement_scope_time
                    (tenant_id, source_id, material_id, created_at),
                UNIQUE INDEX uniq_production_material_movement_tenant_key
                    (tenant_id, idempotency_key),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCTION_MATERIAL_MOVEMENT_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_MATERIAL_MOVEMENT_SOURCE
                    FOREIGN KEY (source_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_MATERIAL_MOVEMENT_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, source_id)
                    REFERENCES condor_inventory_source (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_MATERIAL_MOVEMENT_MATERIAL
                    FOREIGN KEY (material_id) REFERENCES condor_production_material (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_MATERIAL_MOVEMENT_MATERIAL_SCOPE
                    FOREIGN KEY (tenant_id, material_id)
                    REFERENCES condor_production_material (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_PRODUCTION_MATERIAL_MOVEMENT_TYPE
                    CHECK (
                        type IN (
                            'adjustment_in',
                            'adjustment_out',
                            'production_consumption'
                        )
                    ),
                CONSTRAINT CHK_PRODUCTION_MATERIAL_MOVEMENT_DELTA
                    CHECK (delta <> 0),
                CONSTRAINT CHK_PRODUCTION_MATERIAL_MOVEMENT_BALANCE
                    CHECK (balance_after >= 0)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $movementCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_production_material_movement',
        );
        $balanceCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_production_material_balance',
        );
        $orderCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_production_order',
        );

        $this->abortIf(
            $movementCount > 0 || $balanceCount > 0 || $orderCount > 0,
            'Rollback bloqueado: existen órdenes o movimientos de Producción Lite.',
        );

        $this->addSql('DROP TABLE condor_production_material_movement');
        $this->addSql('DROP TABLE condor_production_material_balance');
        $this->addSql('DROP TABLE condor_production_order');
    }
}

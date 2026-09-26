<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923180500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Slice 7: pedidos, snapshots de precio y reservas explícitas de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE condor_inventory_balance '
            .'ADD reserved_quantity INT NOT NULL DEFAULT 0 AFTER quantity',
        );
        $this->addSql(
            'ALTER TABLE condor_sales_channel '
            .'ADD UNIQUE INDEX uniq_sales_channel_scope_id '
            .'(tenant_id, legal_entity_id, id)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_order (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                inventory_source_id VARCHAR(26) NOT NULL,
                sales_channel_id VARCHAR(26) DEFAULT NULL,
                customer_id VARCHAR(26) DEFAULT NULL,
                actor_user_id VARCHAR(26) DEFAULT NULL,
                idempotency_key VARCHAR(120) NOT NULL,
                order_status VARCHAR(20) NOT NULL,
                payment_status VARCHAR(20) NOT NULL,
                fulfillment_status VARCHAR(20) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                total_amount_minor BIGINT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                expires_at DATETIME DEFAULT NULL,
                INDEX IDX_ORDER_TENANT (tenant_id),
                INDEX IDX_ORDER_LEGAL (legal_entity_id),
                INDEX IDX_ORDER_SOURCE (inventory_source_id),
                INDEX IDX_ORDER_CHANNEL (sales_channel_id),
                INDEX IDX_ORDER_CUSTOMER (customer_id),
                INDEX IDX_ORDER_EXPIRY (fulfillment_status, expires_at),
                UNIQUE INDEX uniq_order_tenant_key (tenant_id, idempotency_key),
                UNIQUE INDEX uniq_order_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_order_scope_id (tenant_id, legal_entity_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_ORDER_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_ORDER_LEGAL_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id)
                    REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT FK_ORDER_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, inventory_source_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_ORDER_CHANNEL_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, sales_channel_id)
                    REFERENCES condor_sales_channel (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_ORDER_CUSTOMER_SCOPE
                    FOREIGN KEY (tenant_id, customer_id)
                    REFERENCES condor_customer (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_ORDER_AMOUNT
                    CHECK (total_amount_minor BETWEEN 0 AND 9007199254740991),
                CONSTRAINT CHK_ORDER_STATUS
                    CHECK (order_status IN ('open', 'cancelled')),
                CONSTRAINT CHK_ORDER_PAYMENT_STATUS
                    CHECK (payment_status IN ('pending', 'paid')),
                CONSTRAINT CHK_ORDER_FULFILLMENT_STATUS
                    CHECK (fulfillment_status IN ('reserved', 'released', 'consumed'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_order_line (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                order_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                price_list_id VARCHAR(26) NOT NULL,
                price_rule_id VARCHAR(26) DEFAULT NULL,
                quantity INT NOT NULL,
                base_amount_minor BIGINT NOT NULL,
                effective_amount_minor BIGINT NOT NULL,
                line_total_minor BIGINT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_ORDER_LINE_ORDER (order_id),
                INDEX IDX_ORDER_LINE_VARIANT (variant_id),
                UNIQUE INDEX uniq_order_line_scope_variant (tenant_id, order_id, variant_id),
                UNIQUE INDEX uniq_order_line_scope_id (tenant_id, legal_entity_id, order_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_ORDER_LINE_ORDER_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, order_id)
                    REFERENCES condor_order (tenant_id, legal_entity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_ORDER_LINE_VARIANT_SCOPE
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_ORDER_LINE_PRICE_LIST_SCOPE
                    FOREIGN KEY (tenant_id, price_list_id)
                    REFERENCES condor_price_list (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_ORDER_LINE_QUANTITY
                    CHECK (quantity > 0),
                CONSTRAINT CHK_ORDER_LINE_MONEY
                    CHECK (
                        base_amount_minor BETWEEN 0 AND 9007199254740991
                        AND effective_amount_minor BETWEEN 0 AND 9007199254740991
                        AND line_total_minor BETWEEN 0 AND 9007199254740991
                    )
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_order_event (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                order_id VARCHAR(26) NOT NULL,
                type VARCHAR(60) NOT NULL,
                actor_user_id VARCHAR(26) DEFAULT NULL,
                context JSON NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_order_event_order_time (tenant_id, order_id, created_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_ORDER_EVENT_ORDER_SCOPE
                    FOREIGN KEY (tenant_id, order_id)
                    REFERENCES condor_order (tenant_id, id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_inventory_reservation (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                source_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                order_id VARCHAR(26) NOT NULL,
                order_line_id VARCHAR(26) NOT NULL,
                quantity INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                reserve_key VARCHAR(120) NOT NULL,
                release_key VARCHAR(120) DEFAULT NULL,
                consume_key VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_RESERVATION_SOURCE_VARIANT (tenant_id, legal_entity_id, source_id, variant_id),
                INDEX IDX_RESERVATION_ORDER (order_id),
                UNIQUE INDEX uniq_inventory_reservation_line (tenant_id, order_line_id),
                UNIQUE INDEX uniq_inventory_reservation_reserve_key (tenant_id, reserve_key),
                UNIQUE INDEX uniq_inventory_reservation_release_key (tenant_id, release_key),
                UNIQUE INDEX uniq_inventory_reservation_consume_key (tenant_id, consume_key),
                PRIMARY KEY(id),
                CONSTRAINT FK_RESERVATION_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, source_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_RESERVATION_VARIANT_SCOPE
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_RESERVATION_ORDER_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, order_id)
                    REFERENCES condor_order (tenant_id, legal_entity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_RESERVATION_LINE_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, order_id, order_line_id)
                    REFERENCES condor_order_line (tenant_id, legal_entity_id, order_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT CHK_RESERVATION_QUANTITY CHECK (quantity > 0),
                CONSTRAINT CHK_RESERVATION_STATUS
                    CHECK (status IN ('active', 'released', 'consumed'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $orderCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_order');
        $reservationCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_reservation',
        );
        $reservedBalanceCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_balance WHERE reserved_quantity <> 0',
        );

        $this->abortIf(
            $orderCount > 0 || $reservationCount > 0 || $reservedBalanceCount > 0,
            'Rollback bloqueado: existen pedidos o reservas reales.',
        );

        $this->addSql('DROP TABLE condor_inventory_reservation');
        $this->addSql('DROP TABLE condor_order_event');
        $this->addSql('DROP TABLE condor_order_line');
        $this->addSql('DROP TABLE condor_order');
        $this->addSql(
            'ALTER TABLE condor_sales_channel DROP INDEX uniq_sales_channel_scope_id',
        );
        $this->addSql(
            'ALTER TABLE condor_inventory_balance DROP reserved_quantity',
        );
    }
}

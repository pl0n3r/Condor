<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922184500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Slice 4: fuentes, saldos, movimientos, transferencias y backorder de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE condor_product '
            .'ADD allow_backorder TINYINT(1) NOT NULL DEFAULT 0',
        );
        $this->addSql(
            'ALTER TABLE condor_branch '
            .'ADD UNIQUE INDEX uniq_branch_tenant_legal_id '
            .'(tenant_id, legal_entity_id, id)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_inventory_source (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                branch_id VARCHAR(26) DEFAULT NULL,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                type VARCHAR(20) NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_INV_SOURCE_TENANT (tenant_id),
                INDEX IDX_INV_SOURCE_LEGAL (legal_entity_id),
                INDEX IDX_INV_SOURCE_BRANCH (branch_id),
                UNIQUE INDEX uniq_inventory_source_tenant_legal_slug (tenant_id, legal_entity_id, slug),
                UNIQUE INDEX uniq_inventory_source_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_inventory_source_tenant_legal_id (tenant_id, legal_entity_id, id),
                UNIQUE INDEX uniq_inventory_source_tenant_branch (tenant_id, branch_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_INV_SOURCE_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_INV_SOURCE_LEGAL
                    FOREIGN KEY (legal_entity_id) REFERENCES condor_legal_entity (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_SOURCE_LEGAL_TENANT
                    FOREIGN KEY (tenant_id, legal_entity_id)
                    REFERENCES condor_legal_entity (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_SOURCE_BRANCH
                    FOREIGN KEY (branch_id) REFERENCES condor_branch (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_SOURCE_BRANCH_TENANT
                    FOREIGN KEY (tenant_id, branch_id)
                    REFERENCES condor_branch (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_SOURCE_BRANCH_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, branch_id)
                    REFERENCES condor_branch (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_inventory_balance (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                source_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                quantity INT NOT NULL,
                version INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_INV_BALANCE_TENANT (tenant_id),
                INDEX IDX_INV_BALANCE_LEGAL (legal_entity_id),
                INDEX IDX_INV_BALANCE_SOURCE (source_id),
                INDEX IDX_INV_BALANCE_VARIANT (variant_id),
                UNIQUE INDEX uniq_inventory_balance_scope_variant
                    (tenant_id, legal_entity_id, source_id, variant_id),
                UNIQUE INDEX uniq_inventory_balance_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_INV_BALANCE_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_INV_BALANCE_LEGAL
                    FOREIGN KEY (legal_entity_id) REFERENCES condor_legal_entity (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_BALANCE_LEGAL_TENANT
                    FOREIGN KEY (tenant_id, legal_entity_id)
                    REFERENCES condor_legal_entity (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_BALANCE_SOURCE
                    FOREIGN KEY (source_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_BALANCE_VARIANT
                    FOREIGN KEY (variant_id) REFERENCES condor_product_variant (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_BALANCE_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, source_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_BALANCE_VARIANT_TENANT
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_inventory_transfer (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                source_from_id VARCHAR(26) NOT NULL,
                source_to_id VARCHAR(26) NOT NULL,
                quantity INT NOT NULL,
                actor_user_id VARCHAR(26) DEFAULT NULL,
                idempotency_key VARCHAR(120) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_INV_TRANSFER_TENANT (tenant_id),
                INDEX IDX_INV_TRANSFER_LEGAL (legal_entity_id),
                INDEX IDX_INV_TRANSFER_VARIANT (variant_id),
                INDEX IDX_INV_TRANSFER_FROM (source_from_id),
                INDEX IDX_INV_TRANSFER_TO (source_to_id),
                UNIQUE INDEX uniq_inventory_transfer_tenant_id (tenant_id, id),
                UNIQUE INDEX uniq_inventory_transfer_tenant_legal_id (tenant_id, legal_entity_id, id),
                UNIQUE INDEX uniq_inventory_transfer_tenant_key
                    (tenant_id, idempotency_key),
                PRIMARY KEY(id),
                CONSTRAINT FK_INV_TRANSFER_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_INV_TRANSFER_LEGAL
                    FOREIGN KEY (legal_entity_id) REFERENCES condor_legal_entity (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_LEGAL_TENANT
                    FOREIGN KEY (tenant_id, legal_entity_id)
                    REFERENCES condor_legal_entity (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_VARIANT
                    FOREIGN KEY (variant_id) REFERENCES condor_product_variant (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_FROM
                    FOREIGN KEY (source_from_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_TO
                    FOREIGN KEY (source_to_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_ACTOR
                    FOREIGN KEY (actor_user_id) REFERENCES condor_user (id)
                    ON DELETE SET NULL,
                CONSTRAINT FK_INV_TRANSFER_VARIANT_TENANT
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_FROM_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, source_from_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_TRANSFER_TO_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, source_to_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_inventory_movement (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                legal_entity_id VARCHAR(26) NOT NULL,
                source_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                transfer_id VARCHAR(26) DEFAULT NULL,
                type VARCHAR(30) NOT NULL,
                delta INT NOT NULL,
                balance_after INT NOT NULL,
                actor_user_id VARCHAR(26) DEFAULT NULL,
                idempotency_key VARCHAR(120) DEFAULT NULL,
                context JSON NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_inventory_movement_scope_time
                    (tenant_id, legal_entity_id, source_id, variant_id, created_at),
                INDEX idx_inventory_movement_transfer (transfer_id),
                UNIQUE INDEX uniq_inventory_movement_tenant_key
                    (tenant_id, idempotency_key),
                PRIMARY KEY(id),
                CONSTRAINT FK_INV_MOVEMENT_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_INV_MOVEMENT_LEGAL
                    FOREIGN KEY (legal_entity_id) REFERENCES condor_legal_entity (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_LEGAL_TENANT
                    FOREIGN KEY (tenant_id, legal_entity_id)
                    REFERENCES condor_legal_entity (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_SOURCE
                    FOREIGN KEY (source_id) REFERENCES condor_inventory_source (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_VARIANT
                    FOREIGN KEY (variant_id) REFERENCES condor_product_variant (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_TRANSFER
                    FOREIGN KEY (transfer_id) REFERENCES condor_inventory_transfer (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_ACTOR
                    FOREIGN KEY (actor_user_id) REFERENCES condor_user (id)
                    ON DELETE SET NULL,
                CONSTRAINT FK_INV_MOVEMENT_SOURCE_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, source_id)
                    REFERENCES condor_inventory_source (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_VARIANT_TENANT
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_INV_MOVEMENT_TRANSFER_SCOPE
                    FOREIGN KEY (tenant_id, legal_entity_id, transfer_id)
                    REFERENCES condor_inventory_transfer (tenant_id, legal_entity_id, id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $movementCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_movement',
        );
        $transferCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_transfer',
        );
        $balanceCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_balance',
        );
        $sourceCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_inventory_source',
        );
        $backorderCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_product WHERE allow_backorder = 1',
        );

        $this->abortIf(
            $movementCount > 0
            || $transferCount > 0
            || $balanceCount > 0
            || $sourceCount > 0
            || $backorderCount > 0,
            'Rollback bloqueado: existen datos o configuración real de inventario.',
        );

        $this->addSql('DROP TABLE condor_inventory_movement');
        $this->addSql('DROP TABLE condor_inventory_transfer');
        $this->addSql('DROP TABLE condor_inventory_balance');
        $this->addSql('DROP TABLE condor_inventory_source');
        $this->addSql(
            'ALTER TABLE condor_branch DROP INDEX uniq_branch_tenant_legal_id',
        );
        $this->addSql('ALTER TABLE condor_product DROP allow_backorder');
    }
}

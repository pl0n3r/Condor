<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004143000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Producción Lite V1: BOM versionada tenant-scoped por variante y materiales.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_production_bom (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                variant_id VARCHAR(26) NOT NULL,
                version INT UNSIGNED NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                INDEX IDX_PRODUCTION_BOM_TENANT (tenant_id),
                INDEX IDX_PRODUCTION_BOM_VARIANT (variant_id),
                UNIQUE INDEX uniq_production_bom_tenant_variant_version
                    (tenant_id, variant_id, version),
                UNIQUE INDEX uniq_production_bom_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCTION_BOM_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_BOM_VARIANT
                    FOREIGN KEY (variant_id) REFERENCES condor_product_variant (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_BOM_VARIANT_SCOPE
                    FOREIGN KEY (tenant_id, variant_id)
                    REFERENCES condor_product_variant (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_PRODUCTION_BOM_VERSION
                    CHECK (version > 0)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_production_bom_line (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                bom_id VARCHAR(26) NOT NULL,
                material_id VARCHAR(26) NOT NULL,
                quantity DECIMAL(19,6) NOT NULL,
                unit_of_measure VARCHAR(16) NOT NULL,
                INDEX IDX_PRODUCTION_BOM_LINE_TENANT (tenant_id),
                INDEX IDX_PRODUCTION_BOM_LINE_BOM (bom_id),
                INDEX IDX_PRODUCTION_BOM_LINE_MATERIAL (material_id),
                UNIQUE INDEX uniq_production_bom_line_tenant_bom_material
                    (tenant_id, bom_id, material_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCTION_BOM_LINE_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_BOM_LINE_BOM
                    FOREIGN KEY (bom_id) REFERENCES condor_production_bom (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_BOM_LINE_BOM_SCOPE
                    FOREIGN KEY (tenant_id, bom_id)
                    REFERENCES condor_production_bom (tenant_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_PRODUCTION_BOM_LINE_MATERIAL
                    FOREIGN KEY (material_id) REFERENCES condor_production_material (id)
                    ON DELETE RESTRICT,
                CONSTRAINT FK_PRODUCTION_BOM_LINE_MATERIAL_SCOPE
                    FOREIGN KEY (tenant_id, material_id)
                    REFERENCES condor_production_material (tenant_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT CHK_PRODUCTION_BOM_LINE_QUANTITY
                    CHECK (quantity > 0),
                CONSTRAINT CHK_PRODUCTION_BOM_LINE_UNIT
                    CHECK (unit_of_measure IN ('unit', 'kg', 'g', 'l', 'ml', 'm'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $lineCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_production_bom_line',
        );
        $bomCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_production_bom',
        );
        $this->abortIf(
            $lineCount > 0 || $bomCount > 0,
            'Rollback bloqueado: existen BOM de producción o líneas asociadas.',
        );

        $this->addSql('DROP TABLE condor_production_bom_line');
        $this->addSql('DROP TABLE condor_production_bom');
    }
}

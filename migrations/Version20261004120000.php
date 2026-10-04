<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004120000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Producción Lite V1: materiales tenant-scoped y unidad de medida canónica.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_production_material (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                unit_of_measure VARCHAR(16) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_PRODUCTION_MATERIAL_TENANT (tenant_id),
                UNIQUE INDEX uniq_production_material_tenant_code (tenant_id, code),
                UNIQUE INDEX uniq_production_material_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRODUCTION_MATERIAL_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT CHK_PRODUCTION_MATERIAL_UNIT
                    CHECK (unit_of_measure IN ('unit', 'kg', 'g', 'l', 'ml', 'm'))
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $materialCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_production_material',
        );
        $this->abortIf(
            $materialCount > 0,
            'Rollback bloqueado: existen materiales de producción.',
        );

        $this->addSql('DROP TABLE condor_production_material');
    }
}

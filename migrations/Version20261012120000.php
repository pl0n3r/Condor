<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012120000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Compras Lite V1: proveedores por tenant, códigos únicos y plazos acotados.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_purchasing_supplier (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_PURCHASING_SUPPLIER_TENANT (tenant_id),
                UNIQUE INDEX uniq_purchasing_supplier_tenant_code (tenant_id, code),
                UNIQUE INDEX uniq_purchasing_supplier_tenant_id (tenant_id, id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PURCHASING_SUPPLIER_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT CHK_PURCHASING_SUPPLIER_PAYMENT_TERMS
                    CHECK (payment_terms_days BETWEEN 0 AND 365)
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $supplierCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_purchasing_supplier',
        );
        $this->abortIf(
            $supplierCount > 0,
            'Rollback bloqueado: existen proveedores registrados.',
        );

        $this->addSql('DROP TABLE condor_purchasing_supplier');
    }
}

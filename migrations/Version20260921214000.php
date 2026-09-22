<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921214000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade identidad pública por tenant y blinda el dominio primario verificado.';
    }

    public function up(Schema $schema): void
    {
        $duplicates = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT tenant_id
                FROM condor_tenant_domain
                WHERE is_primary = 1 AND is_verified = 1
                GROUP BY tenant_id
                HAVING COUNT(*) > 1
            ) duplicate_primary
            SQL);

        $this->abortIf(
            $duplicates > 0,
            'Existen tenants con más de un dominio primario verificado. '
            .'La transición requiere reconciliar esos datos antes de continuar.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_storefront_profile (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                headline VARCHAR(120) NOT NULL,
                description VARCHAR(500) NOT NULL,
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_storefront_profile_tenant (tenant_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_storefront_profile_tenant
                    FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        // Valor calculado por la BD: incluso los escritores anteriores al despliegue
        // quedan sujetos al índice único durante una transición con código mixto.
        $this->addSql(<<<'SQL'
            ALTER TABLE condor_tenant_domain
                ADD primary_verified_tenant_id VARCHAR(26)
                    GENERATED ALWAYS AS (
                        IF(is_primary = 1 AND is_verified = 1, tenant_id, NULL)
                    ) PERSISTENT,
                ADD UNIQUE INDEX uniq_domain_primary_verified_tenant
                    (primary_verified_tenant_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $existing = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_storefront_profile',
        );
        $this->abortIf(
            $existing > 0,
            'Rollback bloqueado: existen perfiles públicos de empresas. '
            .'Requiere transición de datos explícitamente autorizada.',
        );

        $this->addSql(
            'DROP INDEX uniq_domain_primary_verified_tenant '
            .'ON condor_tenant_domain',
        );
        $this->addSql(
            'ALTER TABLE condor_tenant_domain '
            .'DROP primary_verified_tenant_id',
        );
        $this->addSql('DROP TABLE condor_storefront_profile');
    }
}

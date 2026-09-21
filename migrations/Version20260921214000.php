<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921214000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Identidad pública editable por tenant para el primer storefront real.';
    }

    public function up(Schema $schema): void
    {
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
        $this->addSql('DROP TABLE condor_storefront_profile');
    }
}

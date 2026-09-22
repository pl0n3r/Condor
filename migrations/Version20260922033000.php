<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922033000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Garantiza un único dominio primario verificado por tenant.';
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

        $this->addSql(
            'ALTER TABLE condor_tenant_domain '
            .'ADD primary_verified_tenant_id VARCHAR(26) DEFAULT NULL',
        );
        $this->addSql(
            'UPDATE condor_tenant_domain '
            .'SET primary_verified_tenant_id = tenant_id '
            .'WHERE is_primary = 1 AND is_verified = 1',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_domain_primary_verified_tenant '
            .'ON condor_tenant_domain (primary_verified_tenant_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX uniq_domain_primary_verified_tenant '
            .'ON condor_tenant_domain',
        );
        $this->addSql(
            'ALTER TABLE condor_tenant_domain '
            .'DROP primary_verified_tenant_id',
        );
    }
}

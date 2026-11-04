<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Group 02 of #1490: preserve the original unique keys, generated column and
 * foreign-key coverage while aligning Doctrine index names.
 */
final class Version20261207121000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Drift 02: nombres de índices únicos de dominio verificado y storefront.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_tenant_domain RENAME INDEX uniq_domain_primary_verified_tenant TO UNIQ_B8F8428B9676AA7E');
        $this->addSql('ALTER TABLE condor_storefront_profile RENAME INDEX uniq_storefront_profile_tenant TO UNIQ_5DBD65159033212A');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_storefront_profile RENAME INDEX UNIQ_5DBD65159033212A TO uniq_storefront_profile_tenant');
        $this->addSql('ALTER TABLE condor_tenant_domain RENAME INDEX UNIQ_B8F8428B9676AA7E TO uniq_domain_primary_verified_tenant');
    }
}

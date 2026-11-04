<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Group 01 of #1490: rename existing unique keys without rebuilding or
 * relaxing their constraints. D-054 still gates every real deployment.
 */
final class Version20261207120000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Drift 01: alinear los nombres de índices únicos de tenant y dominio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_tenant RENAME INDEX UNIQ_TENANT_SLUG TO UNIQ_7B8C3E58989D9B62');
        $this->addSql('ALTER TABLE condor_tenant_domain RENAME INDEX UNIQ_DOMAIN_HOSTNAME TO UNIQ_B8F8428BE551C011');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_tenant_domain RENAME INDEX UNIQ_B8F8428BE551C011 TO UNIQ_DOMAIN_HOSTNAME');
        $this->addSql('ALTER TABLE condor_tenant RENAME INDEX UNIQ_7B8C3E58989D9B62 TO UNIQ_TENANT_SLUG');
    }
}

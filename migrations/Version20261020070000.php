<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Additive; historical orders remain NULL until they have genuine source evidence. */
final class Version20261020070000 extends AbstractMigration // NOSONAR -- nombre Doctrine
{
    public function getDescription(): string
    {
        return 'Snapshot JSON inmutable de costo de producción, sin revalorizar órdenes previas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_production_order ADD cost_snapshot JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_production_order DROP cost_snapshot');
    }
}

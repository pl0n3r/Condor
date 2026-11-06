<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** #1682: tarifas por versión, sin tocar ni borrar las versiones históricas. */
final class Version20261106150000 extends AbstractMigration // NOSONAR -- nombre canónico Doctrine
{
    public function getDescription(): string
    {
        return 'Añade snapshot JSON opcional de tarifas a PlanVersion sin cobros.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('condor_commercial_plan_version')) {
            return;
        }
        if (!$schema->getTable('condor_commercial_plan_version')->hasColumn('price_options')) {
            $this->addSql('ALTER TABLE condor_commercial_plan_version ADD price_options JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Las versiones comerciales pueden contener tarifas: no borrar su historial.');
    }
}

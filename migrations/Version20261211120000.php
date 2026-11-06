<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Expansion-only for synthetic M10 assets. D-054 backup and dry-run gate remain
 * mandatory before deploying; no live API is enabled by this schema change.
 */
final class Version20261211120000 extends AbstractMigration // NOSONAR -- Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Columna de responsable ficticio en activos M10, reversible sin perder referencias.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_asset_record ADD responsible_ref VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $assigned = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_asset_record WHERE responsible_ref IS NOT NULL',
        );
        if ($assigned > 0) {
            throw new IrreversibleMigration('Reversión denegada: existen responsables ficticios; backup requerido.');
        }
        $this->addSql('ALTER TABLE condor_asset_record DROP responsible_ref');
    }
}

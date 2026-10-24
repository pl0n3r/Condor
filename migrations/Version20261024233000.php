<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** D-028: distinguishing services from goods without modifying existing stock. */
final class Version20261024233000 extends AbstractMigration // NOSONAR
{
    public function getDescription(): string
    {
        return 'Catálogo: tipo físico/servicio y duración opcional, compatible con datos existentes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE condor_product ADD item_kind VARCHAR(16) NOT NULL DEFAULT 'physical'");
        $this->addSql('ALTER TABLE condor_product ADD duration_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Eliminar el tipo de producto perdería la distinción de servicios; requiere decisión expresa.',
        );
    }
}

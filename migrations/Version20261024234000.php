<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Solo expansión de esquema; la ejecución requiere las puertas D-054. */
final class Version20261024234000 extends AbstractMigration // NOSONAR -- nombre Doctrine
{
    public function getDescription(): string
    {
        return 'Documento opcional del cliente, sin backfill ni cambios a los datos existentes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE condor_customer ADD document_number VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Eliminar document_number puede destruir datos personales; requiere decisión y plan de rollback.',
        );
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922152000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Optimiza reportes diarios de señales funcionales por fecha y tipo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX idx_functional_signal_created_type '
            .'ON condor_functional_signal (created_at, type)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX idx_functional_signal_created_type '
            .'ON condor_functional_signal',
        );
    }
}

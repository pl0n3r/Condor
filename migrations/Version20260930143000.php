<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930143000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade shadow timestamps exactos para nuevas escrituras; filas V0.1.91 usan fallback desde history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE condor_commercial_subscription
                ADD last_changed_at_exact VARCHAR(32) DEFAULT NULL,
                ADD created_at_exact VARCHAR(32) DEFAULT NULL,
                ADD updated_at_exact VARCHAR(32) DEFAULT NULL
            SQL);


    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_commercial_subscription',
        );

        $this->abortIf(
            $count > 0,
            'Rollback bloqueado: existen suscripciones persistidas. '
            .'La eliminación de timestamps exactos requiere una transición de datos explícitamente autorizada.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE condor_commercial_subscription
                DROP last_changed_at_exact,
                DROP created_at_exact,
                DROP updated_at_exact
            SQL);
    }
}

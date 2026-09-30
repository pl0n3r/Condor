<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930143000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade timestamps exactos ISO-8601 para Subscription Persistence V1 y repara filas V0.1.91.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE condor_commercial_subscription
                ADD last_changed_at_exact VARCHAR(32) DEFAULT NULL,
                ADD created_at_exact VARCHAR(32) DEFAULT NULL,
                ADD updated_at_exact VARCHAR(32) DEFAULT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE condor_commercial_subscription
            SET last_changed_at_exact = JSON_UNQUOTE(
                    JSON_EXTRACT(
                        history,
                        CONCAT('$[', JSON_LENGTH(history) - 1, '].at')
                    )
                ),
                created_at_exact = CONCAT(
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%s.'),
                    LPAD(MICROSECOND(created_at), 6, '0'),
                    'Z'
                ),
                updated_at_exact = CONCAT(
                    DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%s.'),
                    LPAD(MICROSECOND(updated_at), 6, '0'),
                    'Z'
                )
            WHERE JSON_LENGTH(history) > 0
              AND last_changed_at_exact IS NULL
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

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922020000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Observabilidad funcional: señales agregables de actividad de plataforma.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_functional_signal (
                id VARCHAR(26) NOT NULL,
                type VARCHAR(40) NOT NULL,
                tenant_id VARCHAR(26) DEFAULT NULL,
                context JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_functional_signal_type_created (type, created_at),
                INDEX idx_functional_signal_tenant (tenant_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_functional_signal',
        );
        $this->abortIf(
            $count > 0,
            'Rollback bloqueado: existen señales funcionales reales. '
            .'La eliminación requiere una operación explícitamente autorizada.',
        );

        $this->addSql('DROP TABLE condor_functional_signal');
    }
}

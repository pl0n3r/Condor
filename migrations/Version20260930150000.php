<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930150000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade persistencia durable tenant-scoped para observaciones Usage V1.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_usage_observation (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(120) NOT NULL,
                metric VARCHAR(32) NOT NULL,
                quantity BIGINT NOT NULL,
                window_start VARCHAR(32) NOT NULL,
                window_end VARCHAR(32) NOT NULL,
                observed_at VARCHAR(32) NOT NULL,
                INDEX idx_commercial_usage_tenant_metric_window (
                    tenant_id,
                    metric,
                    window_start,
                    window_end
                ),
                INDEX idx_commercial_usage_tenant_observed (
                    tenant_id,
                    observed_at
                ),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_commercial_usage_observation',
            ) > 0,
            'Rollback bloqueado: existen observaciones Usage persistidas.',
        );

        $this->addSql('DROP TABLE condor_commercial_usage_observation');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930133500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Subscription Persistence V1 tenant-scoped con optimistic locking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_subscription (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(120) NOT NULL,
                plan_version_id VARCHAR(26) NOT NULL,
                state VARCHAR(32) NOT NULL,
                history JSON NOT NULL,
                last_changed_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                lock_version INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE INDEX uniq_commercial_subscription_tenant (tenant_id),
                INDEX idx_commercial_subscription_state (state),
                INDEX IDX_COMMERCIAL_SUBSCRIPTION_PLAN_VERSION (plan_version_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_COMMERCIAL_SUBSCRIPTION_PLAN_VERSION
                    FOREIGN KEY (plan_version_id)
                    REFERENCES condor_commercial_plan_version (id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
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
            .'La eliminación requiere una transición de datos explícitamente autorizada.',
        );

        $this->addSql('DROP TABLE condor_commercial_subscription');
    }
}

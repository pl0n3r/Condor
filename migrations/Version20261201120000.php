<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * D-054: additive schema only. No migration execution, backfill, fixture
 * creation, subscription activation or pricing mutation in this change.
 */
final class Version20261201120000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Índice sintético por empresa de observaciones Usage ligadas a la suscripción canónica.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_tenant_subscription_usage (
                tenant_id VARCHAR(120) NOT NULL,
                subscription_id VARCHAR(26) NOT NULL,
                event_key VARCHAR(80) NOT NULL,
                metric VARCHAR(32) NOT NULL,
                quantity BIGINT NOT NULL,
                window_start VARCHAR(32) NOT NULL,
                window_end VARCHAR(32) NOT NULL,
                observed_at VARCHAR(32) NOT NULL,
                actor_id VARCHAR(80) NOT NULL,
                recorded_at VARCHAR(32) NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                PRIMARY KEY (tenant_id, event_key),
                INDEX idx_tenant_subscription_usage (tenant_id, subscription_id, recorded_at),
                CONSTRAINT FK_TENANT_SUBSCRIPTION_USAGE_SUBSCRIPTION
                    FOREIGN KEY (subscription_id)
                    REFERENCES condor_commercial_subscription (id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'No eliminar observaciones de uso sin autorización explícita y backup/restauración.',
        );
    }
}

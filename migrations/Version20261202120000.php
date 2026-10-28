<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Append-only synthetic manual commercial decisions. No real billing. */
final class Version20261202120000 extends AbstractMigration // NOSONAR Doctrine convention
{
    public function getDescription(): string
    {
        return 'Decisiones comerciales manuales sintéticas, auditadas e idempotentes por tenant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_manual_decision (
                id VARCHAR(32) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                subscription_id VARCHAR(26) NOT NULL,
                plan_version_id VARCHAR(26) NOT NULL,
                vertical_id VARCHAR(26) NOT NULL,
                event_key VARCHAR(72) NOT NULL,
                actor_ref VARCHAR(26) NOT NULL,
                approver_ref VARCHAR(26) NOT NULL,
                reason VARCHAR(72) NOT NULL,
                discount_bps INT NOT NULL,
                starts_at VARCHAR(20) NOT NULL,
                renews_at VARCHAR(20) NOT NULL,
                approval_expires_at VARCHAR(20) NOT NULL,
                payment_state VARCHAR(32) NOT NULL,
                catalog_monthly_amount INT NOT NULL,
                effective_monthly_amount INT NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                recorded_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_cmd_tenant_event (tenant_id, event_key),
                INDEX idx_cmd_tenant_subscription (tenant_id, subscription_id, recorded_at),
                CONSTRAINT FK_CMD_TENANT FOREIGN KEY (tenant_id) REFERENCES condor_tenant(id) ON DELETE RESTRICT,
                CONSTRAINT FK_CMD_SUBSCRIPTION FOREIGN KEY (subscription_id) REFERENCES condor_commercial_subscription(id) ON DELETE RESTRICT,
                CONSTRAINT FK_CMD_PLAN FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version(id) ON DELETE RESTRICT,
                CONSTRAINT FK_CMD_VERTICAL FOREIGN KEY (vertical_id) REFERENCES condor_commercial_vertical(id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_commercial_manual_decision');
        if ($count > 0) {
            throw new IrreversibleMigration('No se elimina auditoría comercial no vacía.');
        }
        $this->addSql('DROP TABLE condor_commercial_manual_decision');
    }
}

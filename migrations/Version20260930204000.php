<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930204000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade auditoría durable append-only para cambios de suscripción V1.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_subscription_change (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(120) NOT NULL,
                current_plan_version_id VARCHAR(26) NOT NULL,
                target_plan_version_id VARCHAR(26) NOT NULL,
                direction VARCHAR(16) NOT NULL,
                status VARCHAR(32) NOT NULL,
                requested_at VARCHAR(32) NOT NULL,
                effective_at VARCHAR(32) DEFAULT NULL,
                blockers JSON NOT NULL,
                override_snapshots JSON NOT NULL,
                INDEX idx_commercial_sub_change_tenant_requested (tenant_id, requested_at),
                INDEX idx_commercial_sub_change_tenant_status_effective (tenant_id, status, effective_at),
                INDEX IDX_SUB_CHANGE_CURRENT_PLAN (current_plan_version_id),
                INDEX IDX_SUB_CHANGE_TARGET_PLAN (target_plan_version_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_SUB_CHANGE_CURRENT_PLAN FOREIGN KEY (current_plan_version_id)
                    REFERENCES condor_commercial_plan_version (id) ON DELETE RESTRICT,
                CONSTRAINT FK_SUB_CHANGE_TARGET_PLAN FOREIGN KEY (target_plan_version_id)
                    REFERENCES condor_commercial_plan_version (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_subscription_change_addon (
                subscription_change_id VARCHAR(26) NOT NULL,
                addon_id VARCHAR(26) NOT NULL,
                INDEX IDX_SUB_CHANGE_ADDON_CHANGE (subscription_change_id),
                INDEX IDX_SUB_CHANGE_ADDON_ADDON (addon_id),
                PRIMARY KEY(subscription_change_id, addon_id),
                CONSTRAINT FK_SUB_CHANGE_ADDON_CHANGE FOREIGN KEY (subscription_change_id)
                    REFERENCES condor_commercial_subscription_change (id) ON DELETE CASCADE,
                CONSTRAINT FK_SUB_CHANGE_ADDON_ADDON FOREIGN KEY (addon_id)
                    REFERENCES condor_commercial_addon (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_commercial_subscription_change',
            ) > 0,
            'Rollback bloqueado: existen cambios de suscripción persistidos.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_commercial_subscription_change_addon',
            ) > 0,
            'Rollback bloqueado: existen relaciones de add-ons persistidas.',
        );

        $this->addSql('DROP TABLE condor_commercial_subscription_change_addon');
        $this->addSql('DROP TABLE condor_commercial_subscription_change');
    }
}

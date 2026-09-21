<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921183000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade staff de plataforma, invitaciones seguras y auditoría global.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_platform_staff_grant (
                id VARCHAR(26) NOT NULL,
                staff_user_id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) DEFAULT NULL,
                scope_key VARCHAR(26) NOT NULL,
                module_key VARCHAR(64) NOT NULL,
                actions JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_PLATFORM_STAFF_GRANT_USER (staff_user_id),
                INDEX IDX_PLATFORM_STAFF_GRANT_TENANT (tenant_id),
                UNIQUE INDEX uniq_platform_staff_scope_module (staff_user_id, scope_key, module_key),
                CONSTRAINT FK_PLATFORM_STAFF_GRANT_USER FOREIGN KEY (staff_user_id)
                    REFERENCES condor_user (id) ON DELETE CASCADE,
                CONSTRAINT FK_PLATFORM_STAFF_GRANT_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE,
                CONSTRAINT CHK_PLATFORM_STAFF_SCOPE CHECK (
                    (tenant_id IS NULL AND scope_key = '*')
                    OR (tenant_id IS NOT NULL AND scope_key = tenant_id)
                ),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_account_invitation (
                id VARCHAR(26) NOT NULL,
                user_id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) DEFAULT NULL,
                kind VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                created_by_user_id VARCHAR(26) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                consumed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_ACCOUNT_INVITATION_TENANT (tenant_id),
                UNIQUE INDEX uniq_account_invitation_user (user_id),
                UNIQUE INDEX UNIQ_ACCOUNT_INVITATION_TOKEN_HASH (token_hash),
                CONSTRAINT FK_ACCOUNT_INVITATION_USER FOREIGN KEY (user_id)
                    REFERENCES condor_user (id) ON DELETE CASCADE,
                CONSTRAINT FK_ACCOUNT_INVITATION_TENANT FOREIGN KEY (tenant_id)
                    REFERENCES condor_tenant (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_notification_preference (
                id VARCHAR(26) NOT NULL,
                user_id VARCHAR(26) NOT NULL,
                event_key VARCHAR(120) NOT NULL,
                channel_key VARCHAR(32) NOT NULL,
                enabled TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_NOTIFICATION_PREFERENCE_USER (user_id),
                UNIQUE INDEX uniq_notification_preference (user_id, event_key, channel_key),
                CONSTRAINT FK_NOTIFICATION_PREFERENCE_USER FOREIGN KEY (user_id)
                    REFERENCES condor_user (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_notification_delivery (
                id VARCHAR(26) NOT NULL,
                user_id VARCHAR(26) NOT NULL,
                event_key VARCHAR(120) NOT NULL,
                channel_key VARCHAR(32) NOT NULL,
                payload JSON NOT NULL,
                status VARCHAR(24) NOT NULL,
                attempt_count INT NOT NULL,
                last_error VARCHAR(255) DEFAULT NULL,
                available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_NOTIFICATION_DELIVERY_USER (user_id),
                INDEX idx_notification_delivery_pending (status, available_at),
                CONSTRAINT FK_NOTIFICATION_DELIVERY_USER FOREIGN KEY (user_id)
                    REFERENCES condor_user (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_platform_audit_event (
                id VARCHAR(26) NOT NULL,
                actor_user_id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) DEFAULT NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(120) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                context JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_platform_audit_created (created_at),
                INDEX idx_platform_audit_tenant_created (tenant_id, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $invitationCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_account_invitation',
        );
        $grantCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_platform_staff_grant',
        );
        $auditCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_platform_audit_event',
        );
        $preferenceCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_notification_preference',
        );
        $deliveryCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_notification_delivery',
        );
        $this->abortIf(
            $invitationCount > 0
            || $grantCount > 0
            || $auditCount > 0
            || $preferenceCount > 0
            || $deliveryCount > 0,
            'Rollback bloqueado: existen invitaciones, permisos, notificaciones o auditoría de plataforma.',
        );

        $this->addSql('DROP TABLE condor_notification_delivery');
        $this->addSql('DROP TABLE condor_notification_preference');
        $this->addSql('DROP TABLE condor_platform_audit_event');
        $this->addSql('DROP TABLE condor_account_invitation');
        $this->addSql('DROP TABLE condor_platform_staff_grant');
    }
}

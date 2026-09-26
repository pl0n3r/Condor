<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926040500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'ControlBot: anti-replay por key y auditoría local del contrato Factory D-060.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE condor_user "
            ."ADD last_access_at DATETIME DEFAULT NULL "
            ."COMMENT '(DC2Type:datetime_immutable)'",
        );
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_controlbot_nonce (
                key_id VARCHAR(64) NOT NULL,
                nonce_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_CONTROLBOT_NONCE_EXPIRES (expires_at),
                PRIMARY KEY(key_id, nonce_hash)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_controlbot_idempotency (
                product_key VARCHAR(64) NOT NULL,
                actor_key_id VARCHAR(64) NOT NULL,
                action VARCHAR(120) NOT NULL,
                target_key VARCHAR(180) NOT NULL,
                idempotency_key_hash CHAR(64) NOT NULL,
                request_fingerprint CHAR(64) NOT NULL,
                response_status SMALLINT UNSIGNED DEFAULT NULL,
                response_body JSON DEFAULT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                INDEX IDX_CONTROLBOT_IDEMPOTENCY_EXPIRES (expires_at),
                PRIMARY KEY(
                    product_key,
                    actor_key_id,
                    action,
                    target_key,
                    idempotency_key_hash
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_controlbot_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                action VARCHAR(120) NOT NULL,
                actor_key_id VARCHAR(64) NOT NULL,
                target_staff_user_id VARCHAR(26) DEFAULT NULL,
                result VARCHAR(32) NOT NULL,
                request_id VARCHAR(26) NOT NULL,
                request_nonce_hash CHAR(64) DEFAULT NULL,
                request_ip VARCHAR(45) NOT NULL,
                context JSON NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_CONTROLBOT_AUDIT_CREATED (created_at),
                INDEX IDX_CONTROLBOT_AUDIT_TARGET (target_staff_user_id, created_at),
                INDEX IDX_CONTROLBOT_AUDIT_ACTOR (actor_key_id, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_controlbot_audit');
        $this->addSql('DROP TABLE condor_controlbot_idempotency');
        $this->addSql('DROP TABLE condor_controlbot_nonce');
        $this->addSql('ALTER TABLE condor_user DROP last_access_at');
    }
}

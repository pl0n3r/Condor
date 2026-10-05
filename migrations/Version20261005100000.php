<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005100000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Subscription Commercial Context V1: configuración actual de vertical, cantidades y add-ons.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_subscription_configuration (
                id VARCHAR(26) NOT NULL,
                subscription_id VARCHAR(26) NOT NULL,
                vertical_id VARCHAR(26) NOT NULL,
                quantities JSON NOT NULL,
                configured_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME(6) NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                lock_version INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE INDEX uniq_commercial_subscription_configuration_subscription
                    (subscription_id),
                INDEX IDX_COMMERCIAL_SUBSCRIPTION_CONFIGURATION_VERTICAL (vertical_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_COMMERCIAL_SUBSCRIPTION_CONFIGURATION_SUBSCRIPTION
                    FOREIGN KEY (subscription_id)
                    REFERENCES condor_commercial_subscription (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_COMMERCIAL_SUBSCRIPTION_CONFIGURATION_VERTICAL
                    FOREIGN KEY (vertical_id)
                    REFERENCES condor_commercial_vertical (id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_subscription_configuration_addon (
                subscription_configuration_id VARCHAR(26) NOT NULL,
                addon_id VARCHAR(26) NOT NULL,
                INDEX IDX_SUBSCRIPTION_CONFIGURATION_ADDON_CONFIGURATION
                    (subscription_configuration_id),
                INDEX IDX_SUBSCRIPTION_CONFIGURATION_ADDON_ADDON (addon_id),
                PRIMARY KEY(subscription_configuration_id, addon_id),
                CONSTRAINT FK_SUBSCRIPTION_CONFIGURATION_ADDON_CONFIGURATION
                    FOREIGN KEY (subscription_configuration_id)
                    REFERENCES condor_commercial_subscription_configuration (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_SUBSCRIPTION_CONFIGURATION_ADDON_ADDON
                    FOREIGN KEY (addon_id)
                    REFERENCES condor_commercial_addon (id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_commercial_subscription_configuration',
        );

        $this->abortIf(
            $count > 0,
            'Rollback bloqueado: existen configuraciones comerciales de suscripción. '
            .'La eliminación requiere una transición de datos explícitamente autorizada.',
        );

        $this->addSql(
            'DROP TABLE condor_commercial_subscription_configuration_addon',
        );
        $this->addSql(
            'DROP TABLE condor_commercial_subscription_configuration',
        );
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927060000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Plan Configurator #277: relevancia persistida Vertical-Capability.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_vertical_capability (
                id VARCHAR(26) NOT NULL,
                relation_key VARCHAR(140) NOT NULL,
                vertical_id VARCHAR(26) NOT NULL,
                capability_id VARCHAR(26) NOT NULL,
                priority SMALLINT UNSIGNED NOT NULL,
                UNIQUE INDEX uniq_commercial_vertical_capability_key (relation_key),
                UNIQUE INDEX uniq_commercial_vertical_capability_pair (vertical_id, capability_id),
                INDEX idx_commercial_vertical_capability_priority (vertical_id, priority),
                INDEX idx_commercial_vertical_capability_capability (capability_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_COMMERCIAL_VERTICAL_CAP_VERTICAL
                    FOREIGN KEY (vertical_id)
                    REFERENCES condor_commercial_vertical (id) ON DELETE CASCADE,
                CONSTRAINT FK_COMMERCIAL_VERTICAL_CAP_CAPABILITY
                    FOREIGN KEY (capability_id)
                    REFERENCES condor_commercial_capability (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_commercial_vertical_capability');
    }
}

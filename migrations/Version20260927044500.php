<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927044500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Commercial Catalog #268: Plan, PlanVersion y Vertical versionados.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_plan (
                id VARCHAR(26) NOT NULL,
                catalog_key VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_commercial_plan_key (catalog_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_vertical (
                id VARCHAR(26) NOT NULL,
                catalog_key VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_commercial_vertical_key (catalog_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_plan_version (
                id VARCHAR(26) NOT NULL,
                plan_id VARCHAR(26) NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                currency VARCHAR(3) NOT NULL,
                monthly_amount INT UNSIGNED DEFAULT NULL,
                annual_amount INT UNSIGNED DEFAULT NULL,
                quote_required TINYINT(1) NOT NULL,
                limits JSON NOT NULL,
                effective_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                effective_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_commercial_plan_version (plan_id, version_number),
                INDEX idx_commercial_plan_effective (plan_id, effective_from, effective_until),
                PRIMARY KEY(id),
                CONSTRAINT FK_COMMERCIAL_PLAN_VERSION_PLAN
                    FOREIGN KEY (plan_id) REFERENCES condor_commercial_plan (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_commercial_plan_version_vertical (
                plan_version_id VARCHAR(26) NOT NULL,
                vertical_id VARCHAR(26) NOT NULL,
                INDEX IDX_COMMERCIAL_VERSION_VERTICAL_VERSION (plan_version_id),
                INDEX IDX_COMMERCIAL_VERSION_VERTICAL_VERTICAL (vertical_id),
                PRIMARY KEY(plan_version_id, vertical_id),
                CONSTRAINT FK_COMMERCIAL_VERSION_VERTICAL_VERSION
                    FOREIGN KEY (plan_version_id)
                    REFERENCES condor_commercial_plan_version (id)
                    ON DELETE CASCADE,
                CONSTRAINT FK_COMMERCIAL_VERSION_VERTICAL_VERTICAL
                    FOREIGN KEY (vertical_id)
                    REFERENCES condor_commercial_vertical (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_commercial_plan_version_vertical');
        $this->addSql('DROP TABLE condor_commercial_plan_version');
        $this->addSql('DROP TABLE condor_commercial_vertical');
        $this->addSql('DROP TABLE condor_commercial_plan');
    }
}

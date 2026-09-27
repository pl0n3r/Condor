<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927062500 extends AbstractMigration // NOSONAR
{
    public function getDescription(): string
    {
        return 'Plan Configurator #278: cotización trazable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE condor_commercial_quote (id VARCHAR(26) NOT NULL, plan_version_id VARCHAR(26) NOT NULL, vertical_id VARCHAR(26) NOT NULL, cycle VARCHAR(12) NOT NULL, quantities JSON NOT NULL, add_ons JSON NOT NULL, base_amount INT DEFAULT NULL, addon_amount INT DEFAULT NULL, total_amount INT DEFAULT NULL, proposal_required TINYINT(1) NOT NULL, tax_policy VARCHAR(80) DEFAULT NULL, tax_amount INT DEFAULT NULL, status VARCHAR(16) NOT NULL, valid_until DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_COMMERCIAL_QUOTE_VERSION (plan_version_id), INDEX IDX_COMMERCIAL_QUOTE_VERTICAL (vertical_id), PRIMARY KEY(id), CONSTRAINT FK_COMMERCIAL_QUOTE_VERSION FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version (id), CONSTRAINT FK_COMMERCIAL_QUOTE_VERTICAL FOREIGN KEY (vertical_id) REFERENCES condor_commercial_vertical (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_commercial_quote');
    }
}

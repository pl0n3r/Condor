<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927053500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Commercial Catalog #269: capabilities, add-ons y compatibilidad.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE condor_commercial_capability (id VARCHAR(26) NOT NULL, catalog_key VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE INDEX uniq_commercial_capability_key (catalog_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE condor_commercial_addon (id VARCHAR(26) NOT NULL, catalog_key VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, monthly_amount INT UNSIGNED DEFAULT NULL, quote_required TINYINT(1) NOT NULL, UNIQUE INDEX uniq_commercial_addon_key (catalog_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE condor_commercial_plan_version_capability (plan_version_id VARCHAR(26) NOT NULL, capability_id VARCHAR(26) NOT NULL, INDEX IDX_COMMERCIAL_VERSION_CAP_VERSION (plan_version_id), INDEX IDX_COMMERCIAL_VERSION_CAP_CAP (capability_id), PRIMARY KEY(plan_version_id, capability_id), CONSTRAINT FK_COMMERCIAL_VERSION_CAP_VERSION FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version (id) ON DELETE CASCADE, CONSTRAINT FK_COMMERCIAL_VERSION_CAP_CAP FOREIGN KEY (capability_id) REFERENCES condor_commercial_capability (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE condor_commercial_plan_version_addon (plan_version_id VARCHAR(26) NOT NULL, addon_id VARCHAR(26) NOT NULL, INDEX IDX_COMMERCIAL_VERSION_ADDON_VERSION (plan_version_id), INDEX IDX_COMMERCIAL_VERSION_ADDON_ADDON (addon_id), PRIMARY KEY(plan_version_id, addon_id), CONSTRAINT FK_COMMERCIAL_VERSION_ADDON_VERSION FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version (id) ON DELETE CASCADE, CONSTRAINT FK_COMMERCIAL_VERSION_ADDON_ADDON FOREIGN KEY (addon_id) REFERENCES condor_commercial_addon (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_commercial_plan_version_addon');
        $this->addSql('DROP TABLE condor_commercial_plan_version_capability');
        $this->addSql('DROP TABLE condor_commercial_addon');
        $this->addSql('DROP TABLE condor_commercial_capability');
    }
}

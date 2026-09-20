<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fundación multi-tenant: tenant, entidad legal, sede, usuario, membresía, dominios y auditoría.';
    }

    public function up(Schema $schema): void
    {
        $options = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB';

        $this->addSql("CREATE TABLE condor_tenant (id CHAR(26) NOT NULL, name VARCHAR(160) NOT NULL, slug VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_TENANT_SLUG (slug), PRIMARY KEY(id)) ".$options);
        $this->addSql("CREATE TABLE condor_legal_entity (id CHAR(26) NOT NULL, tenant_id CHAR(26) NOT NULL, legal_name VARCHAR(180) NOT NULL, nit VARCHAR(32) DEFAULT NULL, is_primary TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_LEGAL_TENANT (tenant_id), UNIQUE INDEX uniq_legal_tenant_nit (tenant_id, nit), PRIMARY KEY(id)) ".$options);
        $this->addSql("CREATE TABLE condor_branch (id CHAR(26) NOT NULL, tenant_id CHAR(26) NOT NULL, legal_entity_id CHAR(26) DEFAULT NULL, name VARCHAR(160) NOT NULL, slug VARCHAR(120) NOT NULL, is_default TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_BRANCH_TENANT (tenant_id), INDEX IDX_BRANCH_LEGAL (legal_entity_id), UNIQUE INDEX uniq_branch_tenant_slug (tenant_id, slug), PRIMARY KEY(id)) ".$options);
        $this->addSql("CREATE TABLE condor_user (id CHAR(26) NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(160) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_USER_EMAIL (email), PRIMARY KEY(id)) ".$options);
        $this->addSql("CREATE TABLE condor_membership (id CHAR(26) NOT NULL, tenant_id CHAR(26) NOT NULL, user_id CHAR(26) NOT NULL, role_key VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_MEMBERSHIP_TENANT (tenant_id), INDEX IDX_MEMBERSHIP_USER (user_id), UNIQUE INDEX uniq_membership_tenant_user (tenant_id, user_id), PRIMARY KEY(id)) ".$options);
        $this->addSql("CREATE TABLE condor_tenant_domain (id CHAR(26) NOT NULL, tenant_id CHAR(26) NOT NULL, hostname VARCHAR(253) NOT NULL, is_primary TINYINT(1) NOT NULL, is_verified TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_DOMAIN_TENANT (tenant_id), UNIQUE INDEX UNIQ_DOMAIN_HOSTNAME (hostname), PRIMARY KEY(id)) ".$options);
        $this->addSql("CREATE TABLE condor_audit_event (id CHAR(26) NOT NULL, tenant_id CHAR(26) NOT NULL, actor_user_id CHAR(26) DEFAULT NULL, action VARCHAR(120) NOT NULL, entity_type VARCHAR(120) NOT NULL, entity_id VARCHAR(64) NOT NULL, context JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_audit_tenant_created (tenant_id, created_at), PRIMARY KEY(id)) ".$options);

        $this->addSql('ALTER TABLE condor_legal_entity ADD CONSTRAINT FK_LEGAL_TENANT FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE condor_branch ADD CONSTRAINT FK_BRANCH_TENANT FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE condor_branch ADD CONSTRAINT FK_BRANCH_LEGAL FOREIGN KEY (legal_entity_id) REFERENCES condor_legal_entity (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE condor_membership ADD CONSTRAINT FK_MEMBERSHIP_TENANT FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE condor_membership ADD CONSTRAINT FK_MEMBERSHIP_USER FOREIGN KEY (user_id) REFERENCES condor_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE condor_tenant_domain ADD CONSTRAINT FK_DOMAIN_TENANT FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE condor_audit_event ADD CONSTRAINT FK_AUDIT_TENANT FOREIGN KEY (tenant_id) REFERENCES condor_tenant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_audit_event');
        $this->addSql('DROP TABLE condor_tenant_domain');
        $this->addSql('DROP TABLE condor_membership');
        $this->addSql('DROP TABLE condor_branch');
        $this->addSql('DROP TABLE condor_legal_entity');
        $this->addSql('DROP TABLE condor_user');
        $this->addSql('DROP TABLE condor_tenant');
    }
}

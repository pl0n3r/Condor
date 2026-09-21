<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921030000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Slice 2: roles configurables y asignaciones de permisos por sede.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_role (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                name VARCHAR(120) NOT NULL,
                permissions JSON NOT NULL,
                active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_ROLE_TENANT (tenant_id),
                UNIQUE INDEX uniq_role_tenant_name (tenant_id, name),
                UNIQUE INDEX uniq_role_tenant_id (tenant_id, id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(
            'ALTER TABLE condor_membership '
            .'ADD UNIQUE INDEX uniq_membership_tenant_id (tenant_id, id)',
        );
        $this->addSql(
            'ALTER TABLE condor_branch '
            .'ADD UNIQUE INDEX uniq_branch_tenant_id (tenant_id, id)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE condor_branch_role_assignment (
                id VARCHAR(26) NOT NULL,
                tenant_id VARCHAR(26) NOT NULL,
                membership_id VARCHAR(26) NOT NULL,
                branch_id VARCHAR(26) NOT NULL,
                role_id VARCHAR(26) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_ASSIGNMENT_TENANT (tenant_id),
                INDEX IDX_ASSIGNMENT_MEMBERSHIP (membership_id),
                INDEX IDX_ASSIGNMENT_BRANCH (branch_id),
                INDEX IDX_ASSIGNMENT_ROLE (role_id),
                UNIQUE INDEX uniq_branch_role_assignment (membership_id, branch_id, role_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(
            'ALTER TABLE condor_role '
            .'ADD CONSTRAINT FK_ROLE_TENANT FOREIGN KEY (tenant_id) '
            .'REFERENCES condor_tenant (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_TENANT FOREIGN KEY (tenant_id) '
            .'REFERENCES condor_tenant (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_MEMBERSHIP FOREIGN KEY (membership_id) '
            .'REFERENCES condor_membership (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_BRANCH FOREIGN KEY (branch_id) '
            .'REFERENCES condor_branch (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_ROLE FOREIGN KEY (role_id) '
            .'REFERENCES condor_role (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_MEMBERSHIP_TENANT '
            .'FOREIGN KEY (tenant_id, membership_id) '
            .'REFERENCES condor_membership (tenant_id, id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_BRANCH_TENANT '
            .'FOREIGN KEY (tenant_id, branch_id) '
            .'REFERENCES condor_branch (tenant_id, id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE condor_branch_role_assignment '
            .'ADD CONSTRAINT FK_ASSIGNMENT_ROLE_TENANT '
            .'FOREIGN KEY (tenant_id, role_id) '
            .'REFERENCES condor_role (tenant_id, id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $assignmentCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_branch_role_assignment',
        );
        $roleCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_role',
        );
        $this->abortIf(
            $assignmentCount > 0 || $roleCount > 0,
            'Rollback bloqueado: existen roles o asignaciones reales. '
            .'La eliminación requiere una operación explícitamente autorizada.',
        );

        $this->addSql('DROP TABLE condor_branch_role_assignment');
        $this->addSql('DROP TABLE condor_role');
        $this->addSql(
            'ALTER TABLE condor_membership DROP INDEX uniq_membership_tenant_id',
        );
        $this->addSql(
            'ALTER TABLE condor_branch DROP INDEX uniq_branch_tenant_id',
        );
    }
}

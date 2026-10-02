<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002142500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'SaaS Control Center V1: auditoría de ajustes comerciales manuales.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE condor_commercial_subscription_change
                ADD requested_by VARCHAR(26) DEFAULT NULL,
                ADD audit_reason VARCHAR(500) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $audited = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_commercial_subscription_change '
            .'WHERE requested_by IS NOT NULL OR audit_reason IS NOT NULL',
        );
        $this->abortIf(
            $audited > 0,
            'Rollback bloqueado: existen ajustes comerciales auditados.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE condor_commercial_subscription_change
                DROP requested_by,
                DROP audit_reason
            SQL);
    }
}

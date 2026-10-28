<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * M03 fixture-only schema. Never activates SG-SST for real employees.
 * D-054 deployment authorization, backup and allowlist remain mandatory.
 */
final class Version20261101120000 extends AbstractMigration // NOSONAR -- Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Tablas nuevas, sintéticas y aisladas por tenant para registro SG-SST.';
    }

    public function up(Schema $schema): void
    {
        $statements = [
            <<<'SQL'
            CREATE TABLE condor_occupational_record (
                tenant_ref VARCHAR(80) NOT NULL,
                record_ref VARCHAR(80) NOT NULL,
                state VARCHAR(32) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                review_due VARCHAR(10) NULL,
                evidence_ref VARCHAR(80) NULL,
                updated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_ref, record_ref),
                KEY idx_occupational_state (tenant_ref, state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE condor_occupational_audit (
                event_id CHAR(32) NOT NULL PRIMARY KEY,
                created_at DATETIME NOT NULL,
                event VARCHAR(40) NOT NULL,
                actor_ref VARCHAR(80) NOT NULL,
                record_ref VARCHAR(80) NOT NULL,
                tenant_ref VARCHAR(80) NOT NULL,
                KEY idx_occupational_history (tenant_ref, record_ref, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];

        foreach ($statements as $statement) {
            $this->addSql($statement);
        }
    }

    /**
     * MySQL/MariaDB DDL commits implicitly: avoid a deceptive migration transaction.
     * The rollback holds exclusive table locks across counts and immediate DROP.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        // LOCK TABLES blocks all other sessions from writing either table.
        // Acquire both locks before checking emptiness, not after the counts.
        $this->connection->executeStatement(
            'LOCK TABLES condor_occupational_record WRITE, condor_occupational_audit WRITE',
        );

        try {
            $recordCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_occupational_record',
            );
            $eventCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM condor_occupational_audit',
            );
            if (max($recordCount, $eventCount) > 0) {
                throw new IrreversibleMigration(
                    'Retirar SG-SST con filas requiere respaldo y autorización separada.',
                );
            }

            // Execute now, inside the exclusive lock. Queuing addSql() would
            // release control before Doctrine executes the DROP statements.
            $this->connection->executeStatement('DROP TABLE condor_occupational_audit');
            $this->connection->executeStatement('DROP TABLE condor_occupational_record');
        } finally {
            $this->connection->executeStatement('UNLOCK TABLES');
        }
    }
}

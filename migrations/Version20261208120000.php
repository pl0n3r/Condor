<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #1484: outbox durable (tabla aditiva, expand-compatible).
 */
final class Version20261208120000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Outbox durable para efectos externos (correo transaccional).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE condor_outbox_message (id VARCHAR(26) NOT NULL, idempotency_key VARCHAR(190) NOT NULL, topic VARCHAR(80) NOT NULL, payload JSON NOT NULL, status VARCHAR(16) NOT NULL, attempts SMALLINT NOT NULL, last_error VARCHAR(60) DEFAULT NULL, available_at DATETIME NOT NULL, created_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, INDEX idx_outbox_due (status, available_at), UNIQUE INDEX uniq_outbox_idempotency (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_outbox_message');
    }
}

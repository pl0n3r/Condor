<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

/** #1485: pending mailbox challenge and irrevocable one-trial-per-email ledger. */
final class Version20261209120000 extends AbstractMigration // NOSONAR -- Doctrine migration convention
{
    public function getDescription(): string
    {
        return 'Registro de verificación de correo para un único trial por correo normalizado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE condor_public_trial_enrollment (id VARCHAR(26) NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, issued_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, consumed_tenant_id VARCHAR(26) DEFAULT NULL, UNIQUE INDEX uniq_public_trial_email (email), UNIQUE INDEX uniq_public_trial_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_public_trial_enrollment') > 0) {
            throw new RuntimeException('No se puede borrar el historial de elegibilidad de trials.');
        }
        $this->addSql('DROP TABLE condor_public_trial_enrollment');
    }
}

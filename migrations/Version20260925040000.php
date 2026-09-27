<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925040000 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade tokens de recuperación de contraseña de un solo uso.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_password_reset (
                id VARCHAR(26) NOT NULL,
                user_id VARCHAR(26) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                consumed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_password_reset_user (user_id),
                UNIQUE INDEX UNIQ_PASSWORD_RESET_TOKEN_HASH (token_hash),
                CONSTRAINT FK_PASSWORD_RESET_USER FOREIGN KEY (user_id)
                    REFERENCES condor_user (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM condor_password_reset');
        $this->abortIf(
            $count > 0,
            'Rollback bloqueado: existen tokens de recuperación registrados.',
        );

        $this->addSql('DROP TABLE condor_password_reset');
    }
}

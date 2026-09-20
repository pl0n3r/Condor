<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920194500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Añade el singleton bloqueable que garantiza un único propietario global de plataforma.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE condor_platform_owner (
                singleton_key TINYINT UNSIGNED NOT NULL,
                user_id VARCHAR(26) DEFAULT NULL,
                UNIQUE INDEX UNIQ_PLATFORM_OWNER_USER (user_id),
                PRIMARY KEY(singleton_key),
                CONSTRAINT CHK_PLATFORM_OWNER_SINGLETON CHECK (singleton_key = 1),
                CONSTRAINT FK_PLATFORM_OWNER_USER FOREIGN KEY (user_id)
                    REFERENCES condor_user (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql(
            'INSERT INTO condor_platform_owner (singleton_key, user_id) VALUES (1, NULL)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_platform_owner');
    }
}

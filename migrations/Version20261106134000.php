<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * SG-SST review-event stream, limited to opaque references and reason-social scope.
 * The table remains empty until explicit server-side activation by the owner.
 */
final class Version20261106134000 extends AbstractMigration // NOSONAR Doctrine naming convention
{
    public function getDescription(): string
    {
        return 'Persistencia de revisiones SG-SST por tenant y razón social, sin datos de salud.';
    }

    public function up(Schema $schema): void
    {
        $ddl = <<<'SQL'
CREATE TABLE condor_ohs_real_record (
    id VARCHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(26) NOT NULL,
    company_id VARCHAR(26) NOT NULL,
    record_ref VARCHAR(64) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    state VARCHAR(20) NOT NULL,
    version INT NOT NULL,
    previous_id VARCHAR(26) DEFAULT NULL,
    actor_id VARCHAR(26) NOT NULL,
    occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE KEY uq_ohs_company_reference_revision (tenant_id, company_id, record_ref, version),
    KEY idx_ohs_company_state (tenant_id, company_id, state),
    CONSTRAINT fk_ohs_legal_entity FOREIGN KEY (tenant_id, company_id)
        REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT check_ohs_state CHECK (state IN ('PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION')),
    CONSTRAINT check_ohs_kind CHECK (kind IN ('INSPECCION', 'CONTROL', 'CAPACITACION', 'EMERGENCIA')),
    CONSTRAINT check_ohs_version CHECK (version >= 1)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL;
        $this->addSql($ddl);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        $table = 'condor_ohs_real_record';
        $this->connection->executeStatement('LOCK TABLES '.$table.' WRITE');
        try {
            $existing = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            if ($existing !== 0) {
                throw new IrreversibleMigration(
                    'Hay revisiones SG-SST: no se permite eliminar registros sin backup y autorización.',
                );
            }
            $this->connection->executeStatement('DROP TABLE '.$table);
        } finally {
            $this->connection->executeStatement('UNLOCK TABLES');
        }
    }
}

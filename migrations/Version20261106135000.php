<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Tabla append-only de evidencias de cumplimiento; no contiene datos de personas. */
final class Version20261106135000 extends AbstractMigration // NOSONAR Doctrine naming convention
{
    private const TABLE = 'condor_compliance_real_record';

    public function getDescription(): string
    {
        return 'Evidencias de cumplimiento por empresa, sin permitir el borrado de registros auditados.';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            'id VARCHAR(26) NOT NULL PRIMARY KEY',
            'tenant_id VARCHAR(26) NOT NULL',
            'company_id VARCHAR(26) NOT NULL',
            'record_ref VARCHAR(64) NOT NULL',
            'kind VARCHAR(20) NOT NULL',
            'state VARCHAR(20) NOT NULL',
            'version INT NOT NULL',
            'previous_id VARCHAR(26) DEFAULT NULL',
            'actor_id VARCHAR(26) NOT NULL',
            "occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'",
        ];
        $constraints = [
            'UNIQUE KEY uq_legal_compliance_company_reference_revision (tenant_id, company_id, record_ref, version)',
            'KEY idx_legal_compliance_company_state (tenant_id, company_id, state)',
            'CONSTRAINT fk_legal_compliance_legal_entity FOREIGN KEY (tenant_id, company_id)'
                .' REFERENCES condor_legal_entity (tenant_id, id) ON DELETE RESTRICT',
            "CONSTRAINT check_legal_compliance_state CHECK (state IN ('PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'))",
            "CONSTRAINT check_legal_compliance_kind CHECK (kind IN ('OBLIGACION', 'EVIDENCIA', 'REVISION', 'VENCIMIENTO'))",
            'CONSTRAINT check_legal_compliance_version CHECK (version >= 1)',
        ];
        $definition = implode(",\n    ", [...$columns, ...$constraints]);
        $this->addSql(
            'CREATE TABLE condor_compliance_real_record ('.$definition
            .') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        // Bloquear antes de inspeccionar; jamás descartar una bitácora con registros.
        $database = $this->connection;
        $database->executeStatement('LOCK TABLES '.self::TABLE.' WRITE');
        try {
            $rows = (int) $database->fetchOne('SELECT COUNT(*) FROM '.self::TABLE);
            if ($rows > 0) {
                throw new IrreversibleMigration(
                    'La bitácora de Cumplimiento legal contiene revisiones: no se permite borrarla.',
                );
            }
            $database->executeStatement('DROP TABLE '.self::TABLE);
        } finally {
            $database->executeStatement('UNLOCK TABLES');
        }
    }
}

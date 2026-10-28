<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Compliance\Entity\ComplianceRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/**
 * Pre-live, fixture-only storage. Never exposed through an HTTP controller.
 * The caller supplies an authenticated authorization decision; the repository
 * independently rejects foreign tenants, forged evidence and unscoped reads.
 */
final readonly class ComplianceRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(
        ComplianceRecord $record,
        string $tenantRef,
        string $companyRef,
        string $actorRef,
        bool $authorized,
    ): void {
        self::scope($tenantRef, $companyRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef
            || $record->companyRef() !== $companyRef
            || $record->actorRef() !== $actorRef
        ) {
            throw new DomainException('Registro de cumplimiento fuera de la empresa autorizada.');
        }
        $values = $record->snapshot();
        $this->connection->transactional(function () use ($record, $tenantRef, $companyRef, $values): void {
            if ($record->previousRecordId() === null) {
                if ($record->version() !== 1 || $record->previousStatus() !== null) {
                    throw new DomainException('El primer registro debe abrir el caso.');
                }
                $existing = $this->connection->fetchOne(
                    'SELECT id FROM condor_compliance_record '
                    .'WHERE tenant_ref = ? AND company_ref = ? AND obligation_ref = ?'.$this->lockClause(),
                    [$tenantRef, $companyRef, $record->obligationRef()],
                );
                if ($existing !== false) {
                    throw new DomainException('El caso sintético ya existe.');
                }
            } else {
                $previous = $this->connection->fetchAssociative(
                    'SELECT id, status, version FROM condor_compliance_record '
                    .'WHERE tenant_ref = ? AND company_ref = ? AND obligation_ref = ? '
                    .'AND id = ?'.$this->lockClause(),
                    [$tenantRef, $companyRef, $record->obligationRef(), $record->previousRecordId()],
                );
                if ($previous === false
                    || ($previous['id'] ?? null) !== $record->previousRecordId()
                    || ($previous['status'] ?? null) !== $record->previousStatus()
                    || (int) ($previous['version'] ?? 0) + 1 !== $record->version()
                ) {
                    throw new DomainException('La auditoría previa no pertenece a este caso o fue alterada.');
                }
                $latest = $this->connection->fetchOne(
                    'SELECT MAX(version) FROM condor_compliance_record '
                    .'WHERE tenant_ref = ? AND company_ref = ? AND obligation_ref = ?',
                    [$tenantRef, $companyRef, $record->obligationRef()],
                );
                if ((int) $latest + 1 !== $record->version()) {
                    throw new DomainException('Transición obsoleta: versión vigente distinta.');
                }
            }
            // Composite UNIQUE(tenant, company, obligation, version) closes concurrent races.
            $this->connection->insert('condor_compliance_record', $values);
        });
    }

    /**
     * Every query binds both scope identifiers; never fall back to all tenants.
     *
     * @return list<array<string, mixed>>
     */
    public function forCompany(
        string $tenantRef,
        string $companyRef,
        string $actorRef,
        bool $authorized,
    ): array {
        self::scope($tenantRef, $companyRef, $actorRef, $authorized);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_ref, company_ref, obligation_ref, evidence_ref, status, '
            .'version, previous_record_id, previous_status, audit_action, actor_ref, '
            .'occurred_at, fingerprint FROM condor_compliance_record '
            .'WHERE tenant_ref = ? AND company_ref = ? ORDER BY obligation_ref, version',
            [$tenantRef, $companyRef],
        );
        foreach ($rows as $row) {
            if (($row['tenant_ref'] ?? null) !== $tenantRef
                || ($row['company_ref'] ?? null) !== $companyRef
                || !is_string($row['fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['fingerprint']) !== 1
            ) {
                throw new DomainException('Auditoría corrupta o externa al tenant.');
            }
            ComplianceRecord::assertFixture($row['obligation_ref'], 'obligation');
            ComplianceRecord::assertFixture($row['evidence_ref'], 'evidence');
            ComplianceRecord::assertFixture($row['actor_ref'], 'actor');
            $contents = $row;
            unset($contents['fingerprint']);
            $contents['version'] = (int) $contents['version'];
            if (!hash_equals(
                $row['fingerprint'],
                hash('sha256', json_encode($contents, JSON_THROW_ON_ERROR)),
            )) {
                throw new DomainException('Auditoría modificada sin autorización.');
            }
        }

        return $rows;
    }

    private function lockClause(): string
    {
        // SQLite in-memory DBAL acceptance tests use transactional locking.
        // MariaDB must retain SELECT ... FOR UPDATE for concurrent writers.
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private static function scope(
        string $tenantRef,
        string $companyRef,
        string $actorRef,
        bool $authorized,
    ): void {
        ComplianceRecord::assertFixtureScope($tenantRef, $companyRef, $actorRef);
        if (!$authorized) {
            throw new DomainException('Operación de cumplimiento no autorizada.');
        }
    }
}

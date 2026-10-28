<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Governance\Entity\GovernanceRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use DomainException;

/**
 * Append-only history of fictional corporate instruments. No public route or
 * ORM lifecycle hook invokes this repository.
 */
final readonly class GovernanceRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(
        GovernanceRecord $instrument, string $tenant, string $company,
        string $actor, bool $authorized,
    ): void {
        $this->authorize($tenant, $company, $actor, $authorized);
        if ($instrument->tenant() !== $tenant || $instrument->company() !== $company
            || $instrument->author() !== $actor
        ) {
            throw new DomainException('El instrumento pertenece a otro contexto empresarial.');
        }

        $this->connection->transactional(function () use ($instrument, $tenant, $company, $actor): void {
            $this->assertNextRevision($instrument, $tenant, $company);

            $this->connection->insert('condor_governance_entry', $instrument->toRow());
            $this->recordAccess($tenant, $company, $instrument->instrument(), $actor, 'fixture.saved');
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(
        string $tenant, string $company, string $actor, bool $authorized,
    ): array {
        $this->authorize($tenant, $company, $actor, $authorized);
        return $this->connection->transactional(function () use ($tenant, $company, $actor): array {
            $records = $this->connection->fetchAllAssociative(
                'SELECT entry_id, tenant_ref, company_ref, governance_ref, category, state, '
                .'revision, prior_id, actor_ref, action, recorded_at, fingerprint '
                .'FROM condor_governance_entry WHERE tenant_ref = ? AND company_ref = ? '
                .'ORDER BY governance_ref, revision',
                [$tenant, $company],
            );
            foreach ($records as $row) {
                $this->assertReadableRow($row, $tenant, $company);
            }
            $this->recordAccess($tenant, $company, 'fixture:governance/list', $actor, 'fixture.listed');
            return $records;
        });
    }

    /**
     * Validate within the same transaction as the insert; a unique index also
     * serializes competing revisions when no preceding row can be locked.
     */
    private function assertNextRevision(GovernanceRecord $record, string $tenant, string $company): void
    {
        $previous = $this->connection->fetchAssociative(
            'SELECT entry_id, revision FROM condor_governance_entry '
            .'WHERE tenant_ref = ? AND company_ref = ? AND governance_ref = ? '
            .'ORDER BY revision DESC LIMIT 1'.$this->lockForWrite(),
            [$tenant, $company, $record->instrument()],
        );
        $requiredRevision = $previous === false ? 1 : (int) $previous['revision'] + 1;
        $requiredPriorId = $previous === false ? null : $previous['entry_id'];
        if ($record->revision() !== $requiredRevision || $record->prior() !== $requiredPriorId) {
            throw new DomainException('Revisión de instrumento inexistente, duplicada o desactualizada.');
        }
    }

    /** @param array<string,mixed> $row */
    private function assertReadableRow(array $row, string $tenant, string $company): void
    {
        if ($row['tenant_ref'] !== $tenant || $row['company_ref'] !== $company) {
            throw new DomainException('El registro de gobierno pertenece a otro contexto.');
        }
        GovernanceRecord::verifyScope($tenant, $company, $row['actor_ref']);
        GovernanceRecord::checkFixture($row['governance_ref'], 'governance');
        if (!in_array($row['state'], [GovernanceRecord::PENDING, GovernanceRecord::REVIEW], true)
            || !in_array($row['action'], ['CREADA', 'CAMBIO_EXPLICITO'], true)
            || !in_array($row['category'], ['acta', 'organo', 'representante', 'facultad', 'decision'], true)
            || !is_string($row['fingerprint'])
        ) {
            throw new DomainException('El historial de gobierno contiene un valor inválido.');
        }
        $document = $row;
        unset($document['fingerprint']);
        $document['revision'] = (int) $document['revision'];
        $calculated = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
        if (!hash_equals($calculated, $row['fingerprint'])) {
            throw new DomainException('El historial corporativo fue modificado fuera del registro auditado.');
        }
    }

    private function authorize(string $tenant, string $company, string $actor, bool $authorized): void
    {
        GovernanceRecord::verifyScope($tenant, $company, $actor);
        if (!$authorized) {
            throw new DomainException('El actor no puede consultar o modificar gobierno corporativo.');
        }
    }

    private function lockForWrite(): string
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private function recordAccess(
        string $tenant, string $company, string $document, string $actor, string $event,
    ): void {
        $this->connection->insert('condor_governance_journal', [
            'journal_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenant,
            'company_ref' => $company,
            'governance_ref' => $document,
            'actor_ref' => $actor,
            'event_kind' => $event,
            'written_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

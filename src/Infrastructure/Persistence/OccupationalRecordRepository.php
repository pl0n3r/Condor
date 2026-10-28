<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Compliance\Entity\OccupationalRecord;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Offline-only tenant-scoped DBAL persistence. No controller activates it.
 * The composite tenant/occupational key and every WHERE clause fail closed.
 */
final readonly class OccupationalRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Create only a fixture record under the caller's explicit permission.
     * @return array<string, string|null>
     */
    public function create(OccupationalRecord $record, string $tenantRef, string $actorRef, bool $authorized): array
    {
        $this->assertRecordAuthority($record, $tenantRef, $actorRef, $authorized);
        return $this->connection->transactional(function () use ($record, $tenantRef, $actorRef): array {
            $row = $record->snapshot();
            $this->connection->insert('condor_occupational_record', $row);
            $this->audit($tenantRef, $record->recordRef(), $actorRef, 'synthetic.created');
            return $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function forTenant(string $tenantRef, string $actorRef, bool $authorized): array
    {
        OccupationalRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        return $this->connection->transactional(function () use ($tenantRef, $actorRef): array {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT tenant_ref, record_ref, actor_ref, state, evidence_ref, review_due, created_at, updated_at '
                .'FROM condor_occupational_record WHERE tenant_ref = ? ORDER BY record_ref',
                [$tenantRef],
            );
            foreach ($rows as $row) {
                if (($row['tenant_ref'] ?? null) !== $tenantRef
                    || !is_string($row['record_ref'] ?? null)) {
                    throw new DomainException('Consulta de registros SG-SST fuera del ámbito autorizado.');
                }
                OccupationalRecord::assertOccupationalRef($row['record_ref']);
            }
            $this->audit($tenantRef, 'fixture:occupational/list', $actorRef, 'synthetic.listed');
            return $rows;
        });
    }

    /** @return array<string,mixed>|null */
    public function view(string $tenantRef, string $recordRef, string $actorRef, bool $authorized): ?array
    {
        OccupationalRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        OccupationalRecord::assertOccupationalRef($recordRef);
        return $this->connection->transactional(function () use ($tenantRef, $recordRef, $actorRef): ?array {
            $row = $this->connection->fetchAssociative(
                'SELECT tenant_ref, record_ref, actor_ref, state, evidence_ref, review_due, created_at, updated_at '
                .'FROM condor_occupational_record WHERE tenant_ref = ? AND record_ref = ?',
                [$tenantRef, $recordRef],
            );
            if ($row !== false && (($row['tenant_ref'] ?? null) !== $tenantRef
                || ($row['record_ref'] ?? null) !== $recordRef)) {
                throw new DomainException('Registro fuera de ámbito.');
            }
            $this->audit($tenantRef, $recordRef, $actorRef, 'synthetic.viewed');
            return $row === false ? null : $row;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenantRef,
        string $recordRef,
        string $actorRef,
        bool $authorized,
        string $state,
        ?string $evidenceRef = null,
    ): array {
        OccupationalRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        OccupationalRecord::assertOccupationalRef($recordRef);
        return $this->connection->transactional(function () use (
            $tenantRef, $recordRef, $actorRef, $authorized, $state, $evidenceRef,
        ): array {
            $row = $this->connection->fetchAssociative(
                'SELECT tenant_ref, record_ref, actor_ref, state, evidence_ref, review_due, created_at, updated_at '
                .'FROM condor_occupational_record WHERE tenant_ref = ? AND record_ref = ? FOR UPDATE',
                [$tenantRef, $recordRef],
            );
            if ($row === false || ($row['tenant_ref'] ?? null) !== $tenantRef
                || ($row['record_ref'] ?? null) !== $recordRef) {
                throw new DomainException('Registro sintético no encontrado en empresa.');
            }
            // Validate allowed, explicit transitions without ever approving or closing.
            $validation = new OccupationalRecord($tenantRef, $recordRef, $actorRef, $authorized);
            $validation->transition($state, $actorRef, $authorized, $evidenceRef);
            $updated = ['state' => $validation->state(), 'actor_ref' => $actorRef,
                'evidence_ref' => $evidenceRef, 'updated_at' => gmdate('Y-m-d H:i:s')];
            $changed = $this->connection->update(
                'condor_occupational_record', $updated, ['tenant_ref' => $tenantRef, 'record_ref' => $recordRef],
            );
            if ($changed !== 1) {
                throw new DomainException('Concurrencia al actualizar activo sintético.');
            }
            $this->audit($tenantRef, $recordRef, $actorRef, 'synthetic.state_changed');
            return array_replace($row, $updated);
        });
    }

    private function assertRecordAuthority(
        OccupationalRecord $record, string $tenantRef, string $actorRef, bool $authorized,
    ): void {
        OccupationalRecord::assertAuthority($tenantRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef || $record->actorRef() !== $actorRef) {
            throw new DomainException('Registro de activo pertenece a otra empresa o actor.');
        }
    }

    private function audit(string $tenantRef, string $recordRef, string $actorRef, string $event): void
    {
        $this->connection->insert('condor_occupational_audit', [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenantRef,
            'record_ref' => $recordRef,
            'actor_ref' => $actorRef,
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

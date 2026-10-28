<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Compliance\Entity\OccupationalRecord;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * M03 fixture persistence. Every operation authorizes its tenant and writes
 * an audit event atomically; no route or service makes this live.
 */
final readonly class OccupationalRecordRepository
{
    public function __construct(private Connection $database)
    {
    }

    /** @return array<string,string|null> */
    public function create(
        OccupationalRecord $record,
        string $tenantRef,
        string $actorRef,
        bool $authorized,
    ): array {
        $this->permit($tenantRef, $actorRef, $authorized);
        if ($record->tenantRef() !== $tenantRef || $record->actorRef() !== $actorRef) {
            throw new DomainException('El registro no pertenece a este ámbito.');
        }

        return $this->database->transactional(function () use ($record, $tenantRef, $actorRef): array {
            $saved = $record->snapshot();
            $this->database->insert('condor_occupational_record', $saved);
            $this->appendEvent($tenantRef, $record->recordRef(), $actorRef, 'synthetic.created');

            return $saved;
        });
    }

    /** @return list<array<string,mixed>> */
    public function forTenant(string $tenantRef, string $actorRef, bool $authorized): array
    {
        $this->permit($tenantRef, $actorRef, $authorized);

        return $this->database->transactional(function () use ($tenantRef, $actorRef): array {
            $items = $this->database->fetchAllAssociative(
                'SELECT * FROM condor_occupational_record WHERE tenant_ref = ? ORDER BY record_ref',
                [$tenantRef],
            );
            foreach ($items as $item) {
                $this->assertRow($item, $tenantRef);
            }
            $this->appendEvent($tenantRef, 'fixture:occupational/list', $actorRef, 'synthetic.listed');

            return $items;
        });
    }

    /** @return array<string,mixed>|null */
    public function view(string $tenantRef, string $recordRef, string $actorRef, bool $authorized): ?array
    {
        $this->permit($tenantRef, $actorRef, $authorized);
        OccupationalRecord::assertReference($recordRef);

        return $this->database->transactional(function () use ($tenantRef, $recordRef, $actorRef): ?array {
            $item = $this->find($tenantRef, $recordRef, false);
            $this->appendEvent($tenantRef, $recordRef, $actorRef, 'synthetic.viewed');

            return $item;
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
        $this->permit($tenantRef, $actorRef, $authorized);
        OccupationalRecord::assertReference($recordRef);

        return $this->database->transactional(function () use (
            $tenantRef, $recordRef, $actorRef, $state, $evidenceRef,
        ): array {
            $current = $this->find($tenantRef, $recordRef, true);
            if ($current === null) {
                throw new DomainException('El registro SG-SST no existe en esta empresa.');
            }

            // Validate only an explicit proposed state; no approval operation exists.
            $proposal = new OccupationalRecord($tenantRef, $recordRef, $actorRef, true);
            $proposal->transition($state, $actorRef, true, $evidenceRef);
            $patch = [
                'state' => $proposal->state(),
                'actor_ref' => $actorRef,
                'evidence_ref' => $evidenceRef,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            $affected = $this->database->update(
                'condor_occupational_record',
                $patch,
                ['tenant_ref' => $tenantRef, 'record_ref' => $recordRef],
            );
            if ($affected !== 1) {
                throw new DomainException('La actualización concurrente no fue confirmada.');
            }
            $this->appendEvent($tenantRef, $recordRef, $actorRef, 'synthetic.state_changed');

            return array_replace($current, $patch);
        });
    }

    /** @return array<string,mixed>|null */
    private function find(string $tenantRef, string $recordRef, bool $lock): ?array
    {
        $sql = 'SELECT * FROM condor_occupational_record WHERE tenant_ref = ? AND record_ref = ?';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $result = $this->database->fetchAssociative($sql, [$tenantRef, $recordRef]);
        if ($result === false) {
            return null;
        }
        $this->assertRow($result, $tenantRef, $recordRef);

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function assertRow(array $row, string $tenantRef, ?string $recordRef = null): void
    {
        if (($row['tenant_ref'] ?? null) !== $tenantRef
            || !is_string($row['record_ref'] ?? null)
            || ($recordRef !== null && $row['record_ref'] !== $recordRef)) {
            throw new DomainException('La consulta obtuvo datos fuera del tenant.');
        }
        OccupationalRecord::assertReference($row['record_ref']);
    }

    private function permit(string $tenantRef, string $actorRef, bool $authorized): void
    {
        OccupationalRecord::assertScope($tenantRef, $actorRef, $authorized);
    }

    private function appendEvent(string $tenantRef, string $recordRef, string $actorRef, string $operation): void
    {
        $this->database->insert('condor_occupational_audit', [
            'event_id' => bin2hex(random_bytes(16)),
            'tenant_ref' => $tenantRef,
            'record_ref' => $recordRef,
            'actor_ref' => $actorRef,
            'event' => $operation,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

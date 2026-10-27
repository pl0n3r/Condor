<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\CustomerService\Entity\CustomerCaseRecord;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Offline-only tenant-scoped persistence. The caller must authenticate an
 * operation outside this storage boundary; this repository rejects missing
 * permissions and accepts only opaque fixture actors/case references.
 */
final readonly class CustomerCaseRecordRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(
        string $tenantId,
        CustomerCaseRecord $record,
        string $actorRef,
        bool $authorized,
    ): string {
        self::assertScope($tenantId, $actorRef, $authorized);
        if ($record->tenant()->id() !== $tenantId) {
            throw new DomainException('El caso pertenece a otra empresa.');
        }
        CustomerCaseRecord::assertFixtureReference($record->caseReference());

        return $this->connection->transactional(function () use ($tenantId, $record, $actorRef): string {
            $this->lockTenant($tenantId);
            $now = self::utcNow();
            $this->connection->insert('condor_customer_case_record', [
                'id' => $record->id(),
                'tenant_id' => $tenantId,
                'case_reference' => $record->caseReference(),
                'case_kind' => $record->kind(),
                'status' => CustomerCaseRecord::STATUS_PENDING,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($tenantId, $record->id(), $actorRef, 'create', null, CustomerCaseRecord::STATUS_PENDING);
            return $record->id();
        });
    }

    /** @return array{id:string,tenant_id:string,case_reference:string,case_kind:string,status:string,version:int}|null */
    public function find(string $tenantId, string $caseId, string $actorRef, bool $authorized): ?array
    {
        self::assertScope($tenantId, $actorRef, $authorized);
        self::assertId($caseId);

        return $this->connection->transactional(function () use ($tenantId, $caseId, $actorRef): ?array {
            $row = $this->connection->fetchAssociative(
                'SELECT id, tenant_id, case_reference, case_kind, status, version FROM condor_customer_case_record WHERE tenant_id = ? AND id = ?',
                [$tenantId, $caseId],
            );
            if ($row === false) {
                return null;
            }
            if ($row['tenant_id'] !== $tenantId || $row['id'] !== $caseId) {
                throw new DomainException('La lectura devolvió un caso de otro tenant.');
            }
            CustomerCaseRecord::assertFixtureReference((string) $row['case_reference']);
            if (!in_array($row['status'], [
                CustomerCaseRecord::STATUS_PENDING,
                CustomerCaseRecord::STATUS_REVIEW,
                CustomerCaseRecord::STATUS_CLOSED,
            ], true) || (int) $row['version'] < 1) {
                throw new DomainException('Estado persistido inconsistente.');
            }
            $this->audit($tenantId, $caseId, $actorRef, 'read', (string) $row['status'], (string) $row['status']);
            return [
                'id' => (string) $row['id'],
                'tenant_id' => (string) $row['tenant_id'],
                'case_reference' => (string) $row['case_reference'],
                'case_kind' => (string) $row['case_kind'],
                'status' => (string) $row['status'],
                'version' => (int) $row['version'],
            ];
        });
    }

    /**
     * Explicit state transition with optimistic concurrency and append-only
     * audit. There is no scheduler or automatic "close" path.
     */
    public function transition(
        string $tenantId,
        string $caseId,
        string $actorRef,
        bool $authorized,
        int $expectedVersion,
        string $nextState,
        bool $explicitClose = false,
    ): void {
        self::assertScope($tenantId, $actorRef, $authorized);
        self::assertId($caseId);
        if ($expectedVersion < 1) {
            throw new DomainException('Versión de caso inválida.');
        }
        if (!in_array($nextState, [CustomerCaseRecord::STATUS_REVIEW, CustomerCaseRecord::STATUS_CLOSED], true)) {
            throw new DomainException('Transición de estado desconocida.');
        }

        $this->connection->transactional(function () use (
            $tenantId, $caseId, $actorRef, $expectedVersion, $nextState, $explicitClose,
        ): void {
            $this->lockTenant($tenantId);
            $row = $this->connection->fetchAssociative(
                'SELECT id, tenant_id, status, version FROM condor_customer_case_record WHERE tenant_id = ? AND id = ? FOR UPDATE',
                [$tenantId, $caseId],
            );
            if ($row === false || $row['tenant_id'] !== $tenantId || $row['id'] !== $caseId
                || (int) $row['version'] !== $expectedVersion) {
                throw new DomainException('Caso inexistente, ajeno o versión obsoleta.');
            }
            $prior = (string) $row['status'];
            if (($prior !== CustomerCaseRecord::STATUS_PENDING || $nextState !== CustomerCaseRecord::STATUS_REVIEW)
                && ($prior !== CustomerCaseRecord::STATUS_REVIEW
                    || $nextState !== CustomerCaseRecord::STATUS_CLOSED
                    || $explicitClose !== true)
            ) {
                throw new DomainException('El cierre requiere orden explícita desde revisión.');
            }
            $changed = $this->connection->executeStatement(
                'UPDATE condor_customer_case_record SET status = ?, version = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND version = ?',
                [$nextState, $expectedVersion + 1, self::utcNow(), $tenantId, $caseId, $expectedVersion],
            );
            if ($changed !== 1) {
                throw new DomainException('El caso cambió concurrentemente.');
            }
            $this->audit($tenantId, $caseId, $actorRef, 'transition', $prior, $nextState);
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(string $tenantId, string $caseId, string $actorRef, bool $authorized): array
    {
        self::assertScope($tenantId, $actorRef, $authorized);
        self::assertId($caseId);

        return $this->connection->transactional(function () use ($tenantId, $caseId, $actorRef): array {
            $found = $this->connection->fetchOne(
                'SELECT id FROM condor_customer_case_record WHERE tenant_id = ? AND id = ?',
                [$tenantId, $caseId],
            );
            if ($found === false) {
                return [];
            }
            if ($found !== $caseId) {
                throw new DomainException('Consulta de auditoría inconsistente.');
            }
            $events = $this->connection->fetchAllAssociative(
                'SELECT action, actor_ref, status_from, status_to, recorded_at FROM condor_customer_case_audit WHERE tenant_id = ? AND case_id = ? ORDER BY recorded_at, id',
                [$tenantId, $caseId],
            );
            $this->audit($tenantId, $caseId, $actorRef, 'audit_read', null, null);
            return $events;
        });
    }

    private function lockTenant(string $tenantId): void
    {
        $found = $this->connection->fetchOne('SELECT id FROM condor_tenant WHERE id = ? FOR UPDATE', [$tenantId]);
        if ($found !== $tenantId) {
            throw new DomainException('Tenant no disponible.');
        }
    }

    private function audit(
        string $tenantId,
        string $caseId,
        string $actorRef,
        string $action,
        ?string $before,
        ?string $after,
    ): void {
        $this->connection->insert('condor_customer_case_audit', [
            'id' => bin2hex(random_bytes(16)),
            'tenant_id' => $tenantId,
            'case_id' => $caseId,
            'actor_ref' => $actorRef,
            'action' => $action,
            'status_from' => $before,
            'status_to' => $after,
            'recorded_at' => self::utcNow(),
        ]);
    }

    private static function assertScope(string $tenantId, string $actorRef, bool $authorized): void
    {
        self::assertId($tenantId);
        if (!$authorized || preg_match('/\Afixture:actor\/[a-z][a-z0-9_-]{0,39}\z/D', $actorRef) !== 1) {
            throw new DomainException('Permiso ausente o actor no sintético.');
        }
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $id) !== 1) {
            throw new DomainException('Identificador ULID inválido.');
        }
    }

    private static function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}

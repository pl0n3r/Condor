<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\HumanResources\Entity\HumanResourceRecord as Record;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/** Append-only company-scoped DBAL store; no live data unless HTTP gate is enabled. */
final readonly class HumanResourceRecordService
{
    private const TABLE = 'condor_hr_real_record';

    public function __construct(private Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listRecords(string $tenant, string $company): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.* FROM '.self::TABLE.' r WHERE r.tenant_id = ? AND r.company_id = ?'
            .' AND r.version = (SELECT MAX(v.version) FROM '.self::TABLE.' v'
            .' WHERE v.tenant_id = r.tenant_id AND v.company_id = r.company_id'
            .' AND v.record_ref = r.record_ref) ORDER BY r.record_ref LIMIT 100',
            [$tenant, $company],
        );
        foreach ($rows as $row) {
            Record::fromRow($row, $tenant, $company);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function detail(string $tenant, string $company, string $ref): ?array
    {
        self::ref($ref);
        $row = $this->db->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = ? AND company_id = ? AND record_ref = ?'
            .' ORDER BY version DESC LIMIT 1', [$tenant, $company, $ref],
        );
        if ($row === false) {
            return null;
        }
        Record::fromRow($row, $tenant, $company);
        return $row;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(string $tenant, string $company, string $actor, array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['collaborator_ref', 'kind', 'position_ref', 'record_ref']
            || !is_string($payload['record_ref']) || !is_string($payload['kind'])
            || !is_string($payload['collaborator_ref']) || !is_string($payload['position_ref'])) {
            throw new DomainException('Campos laborales obligatorios inválidos.');
        }
        $record = Record::open($tenant, $company, $payload['record_ref'],
            $payload['kind'], $payload['collaborator_ref'], $payload['position_ref'], $actor);
        try {
            return $this->db->transactional(function () use ($tenant, $company, $record): array {
                $row = $record->snapshot();
                if ($this->detail($tenant, $company, $row['record_ref']) !== null) {
                    throw new DomainException('Referencia laboral duplicada.');
                }
                $this->db->insert(self::TABLE, $row);
                return $row;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('Escritura laboral concurrente rechazada.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    public function changeState(
        string $tenant, string $company, string $actor, string $ref,
        string $next, int $expectedVersion,
    ): array {
        self::ref($ref);
        try {
            return $this->db->transactional(function () use (
                $tenant, $company, $actor, $ref, $next, $expectedVersion,
            ): array {
                $old = $this->detail($tenant, $company, $ref);
                if ($old === null) {
                    throw new DomainException('Registro laboral no encontrado.');
                }
                $row = Record::fromRow($old, $tenant, $company)
                    ->transition($next, $actor, $expectedVersion)->snapshot();
                $this->db->insert(self::TABLE, $row);
                return $row;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('Cambio laboral concurrente rechazado.', 0, $exception);
        }
    }

    private static function ref(string $ref): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref)) {
            throw new DomainException('Referencia laboral inválida.');
        }
    }
}

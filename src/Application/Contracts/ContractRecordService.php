<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Contracts\Entity\ContractRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/**
 * Tenant and legal-entity scoped append-only persistence; no live activation.
 * Each state change inserts a new immutable version inside a transaction.
 */
final readonly class ContractRecordService
{
    private const TABLE = 'condor_contract_real_record';

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
            ContractRecord::fromRealRow($row, $tenant, $company);
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
        ContractRecord::fromRealRow($row, $tenant, $company);
        return $row;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(string $tenant, string $company, string $actor, array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['kind', 'record_ref', 'template_ref', 'worker_ref']
            || !is_string($payload['record_ref'])
            || !is_string($payload['worker_ref'])
            || !is_string($payload['template_ref'])
            || !is_string($payload['kind'])) {
            throw new DomainException('Campos de contratación obligatorios inválidos.');
        }
        $record = ContractRecord::openReal($tenant, $company, $payload['record_ref'],
            $payload['worker_ref'], $payload['template_ref'], $payload['kind'], $actor);
        try {
            return $this->db->transactional(
                fn (): array => $this->insertFirstVersion($tenant, $company, $record),
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('Escritura de contratación concurrente rechazada.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    public function changeState(
        string $tenant, string $company, string $actor,
        string $ref, string $next, int $expectedVersion,
    ): array {
        self::ref($ref);
        try {
            return $this->db->transactional(function () use (
                $tenant, $company, $actor, $ref, $next, $expectedVersion,
            ): array {
                $old = $this->detail($tenant, $company, $ref);
                if ($old === null) {
                    throw new DomainException('Registro de contratación no encontrado.');
                }
                $row = ContractRecord::fromRealRow($old, $tenant, $company)
                    ->transitionReal($next, $actor, $expectedVersion)->realSnapshot();
                $this->db->insert(self::TABLE, $row);
                return $row;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('Cambio de contratación concurrente rechazado.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function insertFirstVersion(string $tenant, string $company, ContractRecord $record): array
    {
        $row = $record->realSnapshot();
        if ($this->detail($tenant, $company, $row['record_ref']) !== null) {
            throw new DomainException('Referencia de contratación duplicada.');
        }
        $this->db->insert(self::TABLE, $row);
        return $row;
    }

    private static function ref(string $ref): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) !== 1) {
            throw new DomainException('Referencia de contratación inválida.');
        }
    }
}

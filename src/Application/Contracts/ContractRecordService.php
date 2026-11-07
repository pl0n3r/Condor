<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Contracts\Entity\ContractRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/**
 * Versioned contract events are append-only and always scoped to one tenant
 * and one legal entity. No method authorizes use of live employment records.
 */
final readonly class ContractRecordService
{
    private const TABLE = 'condor_contract_real_record';

    public function __construct(private Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listRecords(string $tenant, string $company): array
    {
        $query = 'SELECT event.* FROM '.self::TABLE.' event INNER JOIN ('
            .'SELECT record_ref, MAX(version) AS latest FROM '.self::TABLE
            .' WHERE tenant_id = ? AND company_id = ? GROUP BY record_ref'
            .') current ON event.record_ref = current.record_ref'
            .' AND event.version = current.latest'
            .' WHERE event.tenant_id = ? AND event.company_id = ?'
            .' ORDER BY event.record_ref ASC LIMIT 100';
        $rows = $this->db->fetchAllAssociative($query, [$tenant, $company, $tenant, $company]);
        foreach ($rows as $event) {
            ContractRecord::fromRealRow($event, $tenant, $company);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function detail(string $tenant, string $company, string $ref): ?array
    {
        self::assertReference($ref);
        $result = $this->db->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = ? AND company_id = ? AND record_ref = ?'
            .' ORDER BY version DESC LIMIT 1',
            [$tenant, $company, $ref],
        );
        if ($result === false) {
            return null;
        }
        ContractRecord::fromRealRow($result, $tenant, $company);
        return $result;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(string $tenant, string $company, string $actor, array $payload): array
    {
        $fields = ['kind', 'record_ref', 'template_ref', 'worker_ref'];
        if (array_diff(array_keys($payload), $fields) !== []
            || count($payload) !== count($fields)) {
            throw new DomainException('Campos de contratación obligatorios inválidos.');
        }
        foreach ($fields as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new DomainException('Formato de contratación inválido.');
            }
        }

        return $this->atomic(function () use ($tenant, $company, $actor, $payload): array {
            if ($this->detail($tenant, $company, $payload['record_ref']) !== null) {
                throw new DomainException('Referencia de contratación duplicada.');
            }
            return $this->append(ContractRecord::openReal(
                $tenant, $company, $payload['record_ref'], $payload['worker_ref'],
                $payload['template_ref'], $payload['kind'], $actor,
            ));
        });
    }

    /** @return array<string,mixed> */
    public function changeState(
        string $tenant, string $company, string $actor,
        string $ref, string $next, int $expectedVersion,
    ): array {
        return $this->atomic(function () use (
            $tenant, $company, $actor, $ref, $next, $expectedVersion,
        ): array {
            $previous = $this->detail($tenant, $company, $ref);
            if ($previous === null) {
                throw new DomainException('Registro de contratación no encontrado.');
            }
            $event = ContractRecord::fromRealRow($previous, $tenant, $company);
            return $this->append($event->transitionReal($next, $actor, $expectedVersion));
        });
    }

    /** @param callable():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function atomic(callable $operation): array
    {
        try {
            return $this->db->transactional($operation);
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('Versión concurrente de contratación rechazada.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function append(ContractRecord $event): array
    {
        $snapshot = $event->realSnapshot();
        $this->db->insert(self::TABLE, $snapshot);
        return $snapshot;
    }

    private static function assertReference(string $ref): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) !== 1) {
            throw new DomainException('Referencia de contratación inválida.');
        }
    }
}

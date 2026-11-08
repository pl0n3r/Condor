<?php

declare(strict_types=1);

namespace App\Application\Workflow;

use App\Domain\Workflow\Entity\OperationalProcess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/** Ledger append-only de Procesos: no mezcla empresas ni interpreta aprobaciones legales. */
final readonly class OperationalProcessService
{
    private const TABLE = 'condor_operations_process_real_record';

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(string $tenant, string $company): array
    {
        return $this->page($tenant, $company)['records'];
    }

    /**
     * Paginación por referencia opaca para evitar truncar silenciosamente el listado.
     * @return array{records:list<array<string,mixed>>,next_cursor:?string}
     */
    public function page(string $tenant, string $company, ?string $after = null): array
    {
        if ($after !== null) {
            OperationalProcess::assertReference($after);
        }
        $table = self::TABLE;
        $params = ['tenant' => $tenant, 'company' => $company];
        $cursorFilter = '';
        if ($after !== null) {
            $params['after'] = $after;
            $cursorFilter = ' AND entry.record_ref > :after';
        }
        $sql = "SELECT entry.* FROM {$table} entry"
            ." WHERE entry.tenant_id = :tenant AND entry.company_id = :company"
            ." AND entry.version = (SELECT MAX(rev.version) FROM {$table} rev"
            ." WHERE rev.tenant_id = entry.tenant_id AND rev.company_id = entry.company_id"
            ." AND rev.record_ref = entry.record_ref)"
            .$cursorFilter
            .' ORDER BY entry.record_ref ASC LIMIT 101';
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $hasMore = count($rows) > 100;
        $pageRows = array_slice($rows, 0, 100);
        $records = array_map(
            static fn (array $row): array => OperationalProcess::inspect($row, $tenant, $company),
            $pageRows,
        );

        return [
            'records' => $records,
            'next_cursor' => $hasMore ? (string) $records[count($records) - 1]['record_ref'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function one(string $tenant, string $company, string $ref): ?array
    {
        OperationalProcess::assertReference($ref);
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = :tenant AND company_id = :company AND record_ref = :reference'
            .' ORDER BY version DESC LIMIT 1',
            ['tenant' => $tenant, 'company' => $company, 'reference' => $ref],
        );

        return $row === false ? null : OperationalProcess::inspect($row, $tenant, $company);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function add(string $tenant, string $company, string $actor, array $input): array
    {
        if (array_keys($input) !== ['record_ref', 'kind']
            && array_keys($input) !== ['kind', 'record_ref']) {
            throw new DomainException('Solo se admiten la referencia y la categoría controlada.');
        }
        if (!is_string($input['record_ref']) || !is_string($input['kind'])) {
            throw new DomainException('Los identificadores de Procesos deben ser texto controlado.');
        }
        $created = OperationalProcess::begin(
            $tenant, $company, $actor, $input['record_ref'], $input['kind'],
        );

        return $this->persist(function () use ($tenant, $company, $created): array {
            if ($this->one($tenant, $company, $created['record_ref']) !== null) {
                throw new DomainException('El proceso ya tiene una primera versión.');
            }
            $this->connection->insert(self::TABLE, $created);
            return $created;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant, string $company, string $actor, string $ref, string $next, int $version,
    ): array {
        OperationalProcess::assertReference($ref);

        return $this->persist(function () use ($tenant, $company, $actor, $ref, $next, $version): array {
            $record = $this->one($tenant, $company, $ref);
            if ($record === null) {
                throw new DomainException('Proceso no encontrado en la razón social solicitada.');
            }
            $revision = OperationalProcess::revise($record, $tenant, $company, $actor, $next, $version);
            $this->connection->insert(self::TABLE, $revision);
            return $revision;
        });
    }

    /** @param \Closure():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function persist(\Closure $operation): array
    {
        try {
            return $this->connection->transactional($operation);
        } catch (UniqueConstraintViolationException $error) {
            throw new DomainException('Otra operación ya modificó este proceso.', 0, $error);
        }
    }
}

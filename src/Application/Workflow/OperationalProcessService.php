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
        $predicates = [
            'p.tenant_id = :tenant',
            'p.company_id = :company',
            'NOT EXISTS (SELECT 1 FROM '.self::TABLE.' newer'
                .' WHERE newer.tenant_id = p.tenant_id AND newer.company_id = p.company_id'
                .' AND newer.record_ref = p.record_ref AND newer.version > p.version)',
        ];
        $bindings = ['tenant' => $tenant, 'company' => $company];
        if ($after !== null) {
            $predicates[] = 'p.record_ref > :cursor';
            $bindings['cursor'] = $after;
        }
        $query = 'SELECT p.* FROM '.self::TABLE.' p WHERE '
            .implode(' AND ', $predicates).' ORDER BY p.record_ref LIMIT 101';
        $fetched = $this->connection->fetchAllAssociative($query, $bindings);
        $visible = [];
        foreach (array_slice($fetched, 0, 100) as $row) {
            $visible[] = OperationalProcess::inspect($row, $tenant, $company);
        }

        return [
            'records' => $visible,
            'next_cursor' => count($fetched) === 101
                ? (string) $visible[99]['record_ref'] : null,
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
        $fields = array_keys($input);
        sort($fields);
        if ($fields !== ['kind', 'record_ref']
            || !is_string($input['kind'] ?? null) || !is_string($input['record_ref'] ?? null)) {
            throw new DomainException('Se exige una referencia opaca y una categoría de proceso.');
        }
        $initial = OperationalProcess::begin(
            $tenant, $company, $actor, $input['record_ref'], $input['kind'],
        );

        return $this->persist(function () use ($tenant, $company, $initial): array {
            if ($this->one($tenant, $company, $initial['record_ref']) !== null) {
                throw new DomainException('El proceso ya existe en esta razón social.');
            }
            $this->connection->insert(self::TABLE, $initial);
            return $initial;
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

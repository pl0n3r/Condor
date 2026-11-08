<?php

declare(strict_types=1);

namespace App\Application\CustomerService;

use App\Domain\CustomerService\Entity\SupportCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/** Ledger append-only de Casos: no mezcla empresas ni interpreta aprobaciones legales. */
final readonly class SupportCaseService
{
    private const TABLE = 'condor_customer_service_real_case';

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
            SupportCase::assertReference($after);
        }
        $arguments = ['tenant' => $tenant, 'company' => $company];
        $filter = '';
        if ($after !== null) {
            $arguments['cursor'] = $after;
            $filter = 'AND p.record_ref > :cursor';
        }

        $table = self::TABLE;
        $sql = <<<SQL
SELECT p.*
FROM {$table} p
WHERE p.tenant_id = :tenant
  AND p.company_id = :company
  AND NOT EXISTS (SELECT 1 FROM {$table} newer
      WHERE newer.tenant_id = p.tenant_id
        AND newer.company_id = p.company_id
        AND newer.record_ref = p.record_ref
        AND newer.version > p.version)
{$filter}
ORDER BY p.record_ref
LIMIT 101
SQL;
        $rows = $this->connection->fetchAllAssociative($sql, $arguments);
        $cases = array_map(
            static fn (array $data): array => SupportCase::inspect($data, $tenant, $company),
            array_slice($rows, 0, 100),
        );
        $next = count($rows) > count($cases) ? end($cases)['record_ref'] : null;
        return ['records' => $cases, 'next_cursor' => $next];
    }

    /** @return array<string,mixed>|null */
    public function one(string $tenant, string $company, string $ref): ?array
    {
        SupportCase::assertReference($ref);
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = :tenant AND company_id = :company AND record_ref = :reference'
            .' ORDER BY version DESC LIMIT 1',
            ['tenant' => $tenant, 'company' => $company, 'reference' => $ref],
        );

        return $row === false ? null : SupportCase::inspect($row, $tenant, $company);
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
            throw new DomainException('Se exige una referencia opaca y una categoría de caso.');
        }
        $initial = SupportCase::begin(
            $tenant, $company, $actor, $input['record_ref'], $input['kind'],
        );

        return $this->persist(fn (): array => $this->insertNewCase($tenant, $company, $initial));
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function insertNewCase(string $tenant, string $company, array $record): array
    {
        if ($this->one($tenant, $company, $record['record_ref']) !== null) {
            throw new DomainException('Ya existe un caso con esta referencia interna.');
        }
        $this->connection->insert(self::TABLE, $record);
        return $record;
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant, string $company, string $actor, string $ref, string $next, int $version,
    ): array {
        SupportCase::assertReference($ref);

        return $this->persist(function () use ($tenant, $company, $actor, $ref, $next, $version): array {
            $record = $this->one($tenant, $company, $ref);
            if ($record === null) {
                throw new DomainException('Caso no encontrado en la razón social solicitada.');
            }
            $revision = SupportCase::revise($record, $tenant, $company, $actor, $next, $version);
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
            throw new DomainException('Otra operación ya modificó este caso.', 0, $error);
        }
    }
}

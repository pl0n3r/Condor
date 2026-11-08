<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Domain\Assets\Entity\AssetRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/**
 * Append-only audit ledger for asset records. Tenant and legal-company scope
 * must come from authenticated branch authorization, never from the payload.
 */
final readonly class AssetRecordService
{
    private const TABLE = 'condor_asset_real_record';

    public function __construct(private Connection $connection)
    {
    }

    /** @return array{records:list<array<string,mixed>>,next_cursor:?string} */
    public function page(string $tenant, string $company, ?string $after = null): array
    {
        if ($after !== null) {
            AssetRecord::assertRealReference($after);
        }
        $arguments = ['tenant' => $tenant, 'company' => $company];
        $filter = '';
        if ($after !== null) {
            $arguments['cursor'] = $after;
            $filter = 'AND r.record_ref > :cursor';
        }
        $table = self::TABLE;
        $sql = <<<SQL
SELECT r.*
FROM {$table} r
WHERE r.tenant_id = :tenant AND r.company_id = :company
  AND NOT EXISTS (
      SELECT 1 FROM {$table} newer
      WHERE newer.tenant_id = r.tenant_id
        AND newer.company_id = r.company_id
        AND newer.record_ref = r.record_ref
        AND newer.version > r.version
  )
{$filter}
ORDER BY r.record_ref
LIMIT 101
SQL;
        $rows = $this->connection->fetchAllAssociative($sql, $arguments);
        $records = array_map(
            static fn (array $row): array => AssetRecord::inspectReal($row, $tenant, $company),
            array_slice($rows, 0, 100),
        );
        return [
            'records' => $records,
            'next_cursor' => count($rows) > 100 ? $records[99]['record_ref'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function one(string $tenant, string $company, string $ref): ?array
    {
        AssetRecord::assertRealReference($ref);
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = :tenant AND company_id = :company AND record_ref = :reference'
            .' ORDER BY version DESC LIMIT 1',
            ['tenant' => $tenant, 'company' => $company, 'reference' => $ref],
        );
        return $row === false ? null : AssetRecord::inspectReal($row, $tenant, $company);
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function add(string $tenant, string $company, string $actor, array $input): array
    {
        $fields = array_keys($input);
        sort($fields);
        if ($fields !== ['category', 'record_ref']
            && $fields !== ['category', 'record_ref', 'warranty_until']) {
            throw new DomainException('Solo se admiten categoría, referencia y garantía opcional.');
        }
        if (!is_string($input['record_ref'] ?? null) || !is_string($input['category'] ?? null)
            || (array_key_exists('warranty_until', $input)
                && $input['warranty_until'] !== null
                && !is_string($input['warranty_until']))) {
            throw new DomainException('Datos de activo incompletos o inválidos.');
        }
        $initial = AssetRecord::beginReal(
            $tenant, $company, $actor, $input['record_ref'], $input['category'],
            $input['warranty_until'] ?? null,
        );
        return $this->persist(function () use ($tenant, $company, $initial): array {
            if ($this->one($tenant, $company, $initial['record_ref']) !== null) {
                throw new DomainException('El activo ya existe en esta razón social.');
            }
            $this->connection->insert(self::TABLE, $initial);
            return $initial;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant, string $company, string $actor, string $ref,
        string $next, int $expectedVersion, ?string $responsible = null,
        ?string $evidence = null,
    ): array {
        AssetRecord::assertRealReference($ref);
        return $this->persist(function () use (
            $tenant, $company, $actor, $ref, $next, $expectedVersion, $responsible, $evidence,
        ): array {
            $current = $this->one($tenant, $company, $ref);
            if ($current === null) {
                throw new DomainException('Activo no encontrado en esta razón social.');
            }
            $revision = AssetRecord::reviseReal(
                $current, $tenant, $company, $actor, $next, $expectedVersion,
                $responsible, $evidence,
            );
            $this->connection->insert(self::TABLE, $revision);
            return $revision;
        });
    }

    /** @param \Closure():array<string,mixed> $operation
     *  @return array<string,mixed>
     */
    private function persist(\Closure $operation): array
    {
        try {
            return $this->connection->transactional($operation);
        } catch (UniqueConstraintViolationException $previous) {
            throw new DomainException('Otra revisión del activo ganó la carrera.', 0, $previous);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Finance\Entity\FinanceEntry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/** Immutable financial review ledger scoped to real tenant and legal-entity IDs. */
final readonly class FinanceEntryService
{
    private const TABLE = 'condor_finance_real_entry';

    public function __construct(private Connection $connection) {}

    /** @return list<array<string,mixed>> */
    public function all(string $tenant, string $company): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT r.* FROM '.self::TABLE.' r'
            .' WHERE r.tenant_id = ? AND r.company_id = ?'
            .' AND r.version = (SELECT MAX(h.version) FROM '.self::TABLE.' h'
            .' WHERE h.tenant_id = r.tenant_id AND h.company_id = r.company_id'
            .' AND h.entry_ref = r.entry_ref) ORDER BY r.entry_ref LIMIT 100',
            [$tenant, $company],
        );
        return array_map(
            static fn (array $row): array => FinanceEntry::inspect($row, $tenant, $company),
            $result,
        );
    }

    /** @return array<string,mixed>|null */
    public function one(string $tenant, string $company, string $ref): ?array
    {
        FinanceEntry::assertReference($ref);
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = ? AND company_id = ? AND entry_ref = ?'
            .' ORDER BY version DESC LIMIT 1',
            [$tenant, $company, $ref],
        );
        return $row === false ? null : FinanceEntry::inspect($row, $tenant, $company);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function add(string $tenant, string $company, string $actor, array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['entry_ref', 'kind']
            || !is_string($input['entry_ref']) || !is_string($input['kind'])) {
            throw new DomainException('Campos financieros inválidos.');
        }
        $entry = FinanceEntry::begin($tenant, $company, $actor, $input['entry_ref'], $input['kind']);
        return $this->atomic(function () use ($tenant, $company, $entry): array {
            if ($this->one($tenant, $company, $entry['entry_ref']) !== null) {
                throw new DomainException('Referencia financiera duplicada.');
            }
            $this->connection->insert(self::TABLE, $entry);
            return $entry;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant, string $company, string $actor, string $ref, string $next, int $version,
    ): array {
        FinanceEntry::assertReference($ref);
        return $this->atomic(function () use ($tenant, $company, $actor, $ref, $next, $version): array {
            $stored = $this->one($tenant, $company, $ref);
            if ($stored === null) {
                throw new DomainException('Registro financiero no encontrado.');
            }
            $updated = FinanceEntry::revise($stored, $tenant, $company, $actor, $next, $version);
            $this->connection->insert(self::TABLE, $updated);
            return $updated;
        });
    }

    /** @param \Closure():array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function atomic(\Closure $work): array
    {
        try {
            return $this->connection->transactional($work);
        } catch (UniqueConstraintViolationException $e) {
            throw new DomainException('Cambio financiero concurrente rechazado.', 0, $e);
        }
    }
}

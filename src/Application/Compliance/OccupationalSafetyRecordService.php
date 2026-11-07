<?php

declare(strict_types=1);

namespace App\Application\Finance;

use App\Domain\Compliance\Entity\OccupationalSafetyRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/** Immutable occupational-safety review ledger scoped to real tenant and legal-entity IDs. */
final readonly class OccupationalSafetyRecordService
{
    private const TABLE = 'condor_ohs_real_record';

    public function __construct(private Connection $connection) {}

    /** @return list<array<string,mixed>> */
    public function all(string $tenant, string $company): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT r.* FROM '.self::TABLE.' r'
            .' WHERE r.tenant_id = ? AND r.company_id = ?'
            .' AND r.version = (SELECT MAX(h.version) FROM '.self::TABLE.' h'
            .' WHERE h.tenant_id = r.tenant_id AND h.company_id = r.company_id'
            .' AND h.record_ref = r.record_ref) ORDER BY r.record_ref LIMIT 100',
            [$tenant, $company],
        );
        return array_map(
            static fn (array $row): array => OccupationalSafetyRecord::inspect($row, $tenant, $company),
            $result,
        );
    }

    /** @return array<string,mixed>|null */
    public function one(string $tenant, string $company, string $ref): ?array
    {
        OccupationalSafetyRecord::assertReference($ref);
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE
            .' WHERE tenant_id = ? AND company_id = ? AND record_ref = ?'
            .' ORDER BY version DESC LIMIT 1',
            [$tenant, $company, $ref],
        );
        return $row === false ? null : OccupationalSafetyRecord::inspect($row, $tenant, $company);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function add(string $tenant, string $company, string $actor, array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['kind', 'record_ref']
            || !is_string($input['record_ref']) || !is_string($input['kind'])) {
            throw new DomainException('Campos SG-SSTs inválidos.');
        }
        $entry = OccupationalSafetyRecord::begin($tenant, $company, $actor, $input['record_ref'], $input['kind']);
        return $this->atomic(function () use ($tenant, $company, $entry): array {
            if ($this->one($tenant, $company, $entry['record_ref']) !== null) {
                throw new DomainException('Referencia SG-SST duplicada.');
            }
            $this->connection->insert(self::TABLE, $entry);
            return $entry;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant, string $company, string $actor, string $ref, string $next, int $version,
    ): array {
        OccupationalSafetyRecord::assertReference($ref);
        return $this->atomic(function () use ($tenant, $company, $actor, $ref, $next, $version): array {
            $stored = $this->one($tenant, $company, $ref);
            if ($stored === null) {
                throw new DomainException('Registro SG-SST no encontrado.');
            }
            $updated = OccupationalSafetyRecord::revise($stored, $tenant, $company, $actor, $next, $version);
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
            throw new DomainException('Cambio SG-SST concurrente rechazado.', 0, $e);
        }
    }
}

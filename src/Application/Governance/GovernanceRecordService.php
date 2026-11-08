<?php

declare(strict_types=1);

namespace App\Application\Governance;

use App\Domain\Governance\Entity\GovernanceRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/**
 * Tenant-scoped, append-only Gobierno corporativo ledger. Only opaque review references.
 */
final readonly class GovernanceRecordService
{
    private const TABLE = 'condor_governance_real_record';

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(string $tenant, string $company): array
    {
        $latest = $this->connection->fetchAllAssociative(
            'SELECT r.* FROM '.self::TABLE.' r WHERE r.tenant_id = :tenant'
            .' AND r.company_id = :company AND NOT EXISTS (SELECT 1 FROM '.self::TABLE
            .' newer WHERE newer.tenant_id = r.tenant_id AND newer.company_id = r.company_id'
            .' AND newer.record_ref = r.record_ref AND newer.version > r.version)'
            .' ORDER BY r.record_ref LIMIT 100',
            ['tenant' => $tenant, 'company' => $company],
        );

        $verified = [];
        foreach ($latest as $row) {
            $verified[] = GovernanceRecord::inspect($row, $tenant, $company);
        }
        return $verified;
    }

    /** @return array<string,mixed>|null */
    public function one(string $tenant, string $company, string $ref): ?array
    {
        GovernanceRecord::assertReference($ref);
        $query = $this->connection->createQueryBuilder();
        $query->select('record.*')
            ->from(self::TABLE, 'record')
            ->where('record.tenant_id = :tenant')
            ->andWhere('record.company_id = :company')
            ->andWhere('record.record_ref = :ref')
            ->setParameters(['tenant' => $tenant, 'company' => $company, 'ref' => $ref])
            ->orderBy('record.version', 'DESC')
            ->setMaxResults(1);
        $row = $query->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return GovernanceRecord::inspect($row, $tenant, $company);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function add(string $tenant, string $company, string $actor, array $input): array
    {
        if (count($input) !== 2 || !isset($input['kind'], $input['record_ref'])
            || !is_string($input['kind']) || !is_string($input['record_ref'])) {
            throw new DomainException('Solo se admiten categoría y referencia opaca.');
        }
        $created = GovernanceRecord::begin(
            $tenant,
            $company,
            $actor,
            $input['record_ref'],
            $input['kind'],
        );
        return $this->write(function () use ($tenant, $company, $created): array {
            if ($this->one($tenant, $company, $created['record_ref']) !== null) {
                throw new DomainException('La referencia Gobierno corporativo ya existe.');
            }
            $this->connection->insert(self::TABLE, $created);
            return $created;
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        string $tenant, string $company, string $actor, string $ref, string $next, int $version,
    ): array {
        GovernanceRecord::assertReference($ref);
        return $this->write(function () use ($tenant, $company, $actor, $ref, $next, $version): array {
            $current = $this->one($tenant, $company, $ref);
            if ($current === null) {
                throw new DomainException('Referencia Gobierno corporativo no encontrada.');
            }
            $updated = GovernanceRecord::revise(
                $current,
                $tenant,
                $company,
                $actor,
                $next,
                $version,
            );
            $this->connection->insert(self::TABLE, $updated);
            return $updated;
        });
    }

    /** @param \Closure():array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function write(\Closure $action): array
    {
        try {
            return $this->connection->transactional($action);
        } catch (UniqueConstraintViolationException $previous) {
            throw new DomainException('Otra revisión Gobierno corporativo ganó la carrera.', 0, $previous);
        }
    }
}

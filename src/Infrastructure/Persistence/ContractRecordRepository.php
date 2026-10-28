<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\Entity\ContractRecord;

/** Limited, parameterized storage boundary; never accepts raw SQL from a caller. */
interface ContractRecordStorage
{
    /** @param array<string, scalar> $params */
    public function executeStatement(string $sql, array $params): void;

    /**
     * @param array<string, scalar> $params
     * @return list<array<string, mixed>>
     */
    public function selectRows(string $sql, array $params): array;
}

/** DB-backed adapter. Queries are always prepared, not interpolated. */
final class PdoContractRecordStorage implements ContractRecordStorage
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function executeStatement(string $sql, array $params): void
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute($params)) {
            throw new \RuntimeException('contract_persistence_failed');
        }
    }

    public function selectRows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute($params)) {
            throw new \RuntimeException('contract_persistence_failed');
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new \RuntimeException('contract_persistence_failed');
        }
        return $rows;
    }
}

/** Append-only synthetic records, strictly scoped to an authorized company. */
final class ContractRecordRepository
{
    public function __construct(private readonly ContractRecordStorage $storage)
    {
    }

    /**
     * Trusted callers must supply the authenticated actor's tenant/company scope.
     * @return array<string, mixed>
     */
    public function append(
        ContractRecord $record,
        string $actorTenantRef,
        string $actorCompanyRef,
        string $role,
    ): array {
        $this->authorize($actorTenantRef, $actorCompanyRef, $role, true);
        if (!$record->inScope($actorTenantRef, $actorCompanyRef)) {
            throw new \DomainException('scope_forbidden');
        }
        $row = $record->toRow();
        $this->storage->executeStatement(
            'INSERT INTO condor_contract_synthetic_record ' .
            '(id, tenant_ref, company_ref, contract_ref, worker_ref, template_ref, ' .
            'actor_ref, state, version, audit_action, fingerprint) VALUES ' .
            '(:id, :tenant_ref, :company_ref, :contract_ref, :worker_ref, ' .
            ':template_ref, :actor_ref, :state, :version, :audit_action, :fingerprint)',
            [
                ':id' => $row['id'],
                ':tenant_ref' => $row['tenant_ref'],
                ':company_ref' => $row['company_ref'],
                ':contract_ref' => $row['contract_ref'],
                ':worker_ref' => $row['worker_ref'],
                ':template_ref' => $row['template_ref'],
                ':actor_ref' => $row['actor_ref'],
                ':state' => $row['state'],
                ':version' => $row['version'],
                ':audit_action' => $row['audit_action'],
                ':fingerprint' => $row['fingerprint'],
            ],
        );
        return $row;
    }

    /**
     * Both the requested and actor scopes are required, even for owner/editor.
     * @return list<array<string, mixed>>
     */
    public function listByScope(
        string $tenantRef,
        string $companyRef,
        string $actorTenantRef,
        string $actorCompanyRef,
        string $role,
    ): array {
        $this->authorize($actorTenantRef, $actorCompanyRef, $role, false);
        if ($tenantRef !== $actorTenantRef || $companyRef !== $actorCompanyRef) {
            throw new \DomainException('scope_forbidden');
        }
        return $this->storage->selectRows(
            'SELECT id, tenant_ref, company_ref, contract_ref, worker_ref, template_ref, ' .
            'actor_ref, state, version, audit_action, fingerprint, created_at ' .
            'FROM condor_contract_synthetic_record ' .
            'WHERE tenant_ref = :tenant_ref AND company_ref = :company_ref ' .
            'ORDER BY contract_ref, version, id',
            [':tenant_ref' => $tenantRef, ':company_ref' => $companyRef],
        );
    }

    private function authorize(string $tenantRef, string $companyRef, string $role, bool $write): void
    {
        if (!ContractRecord::validTenant($tenantRef)
            || !ContractRecord::validFixture($companyRef, 'company')
            || !in_array($role, $write ? ['owner', 'editor'] : ['owner', 'editor', 'reader'], true)
        ) {
            throw new \DomainException('scope_forbidden');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Application\SocialSecurity;

use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\Branch;
use DomainException;

/**
 * Synthetic, pre-live ledger contract. Implementations MUST atomically commit
 * each mutation and its audit trail with optimistic version checks; queries
 * MUST produce an audit trail and never fetch outside tenant+company.
 */
interface SocialSecurityApiPort
{
    /** @return list<array<string, mixed>>|array<string, mixed>|null */
    public function query(string $tenantRef, string $entityRef, ?string $ref, string $actorRef, string $reason): ?array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function commit(array $data, string $command, string $actorRef, ?int $expectedVersion): array;
}

/** Four M05 operations on synthetic affiliations; never initiates PILA filings. */
final readonly class SocialSecurityApiService
{
    public function __construct(private ?SocialSecurityApiPort $ledger = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(Tenant $tenant, Branch $branch, User $actor, bool $allowed): array
    {
        [$scope, $entity, $who] = $this->authority($tenant, $branch, $actor, $allowed);
        $rows = $this->storage()->query($scope, $entity, null, $who, 'social_security.list');
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) {
            throw new DomainException('Respuesta sintética no válida.');
        }
        foreach ($rows as $row) {
            self::verify($row, $scope, $entity);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function view(Tenant $tenant, Branch $branch, User $actor, bool $allowed, string $slug): ?array
    {
        [$scope, $entity, $who] = $this->authority($tenant, $branch, $actor, $allowed);
        $ref = self::reference('affiliation', $slug);
        $row = $this->storage()->query($scope, $entity, $ref, $who, 'social_security.view');
        if ($row === null) {
            return null;
        }
        self::verify($row, $scope, $entity, $ref);
        return [
            'tenant_ref' => $row['tenant_ref'], 'entity_ref' => $row['entity_ref'],
            'affiliation_ref' => $row['affiliation_ref'], 'employee_ref' => $row['employee_ref'],
            'plan_ref' => $row['plan_ref'], 'state' => $row['state'], 'version' => $row['version'],
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(
        Tenant $tenant, Branch $branch, User $actor, bool $allowed, array $input,
    ): array {
        self::only($input, ['affiliation_ref', 'employee_ref', 'plan_ref']);
        $affiliation = self::reference('affiliation', $input['affiliation_ref']);
        $employee = self::reference('employee', $input['employee_ref']);
        $plan = self::reference('plan', $input['plan_ref']);
        [$scope, $entity, $who] = $this->authority($tenant, $branch, $actor, $allowed);
        $data = [
            'tenant_ref' => $scope, 'entity_ref' => $entity,
            'affiliation_ref' => $affiliation, 'employee_ref' => $employee,
            'plan_ref' => $plan, 'state' => 'BORRADOR', 'version' => 1,
        ];
        $written = $this->storage()->commit($data, 'register', $who, null);
        self::verify($written, $scope, $entity, $affiliation);
        if ($written['state'] !== 'BORRADOR' || $written['version'] !== 1) {
            throw new DomainException('No se puede aprobar automáticamente.');
        }
        return $written;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function revise(
        Tenant $tenant, Branch $branch, User $actor, bool $allowed,
        string $slug, array $input,
    ): array {
        self::only($input, ['state', 'expected_version']);
        $next = $input['state'];
        $version = $input['expected_version'];
        if (!in_array($next, ['BORRADOR', 'EN_REVISION'], true)
            || !is_int($version) || $version < 1 || $version > 1000000) {
            throw new DomainException('Transición de seguridad social ficticia no válida.');
        }
        $affiliation = self::reference('affiliation', $slug);
        [$scope, $entity, $who] = $this->authority($tenant, $branch, $actor, $allowed);
        $updated = $this->storage()->commit([
            'tenant_ref' => $scope, 'entity_ref' => $entity,
            'affiliation_ref' => $affiliation, 'state' => $next,
        ], 'revise', $who, $version);
        self::verify($updated, $scope, $entity, $affiliation);
        if ($updated['version'] !== $version + 1 || $updated['state'] !== $next) {
            throw new DomainException('Conflicto de versión o cambio no autorizado.');
        }
        return $updated;
    }

    private function storage(): SocialSecurityApiPort
    {
        if ($this->ledger === null) {
            throw new DomainException('Almacén sintético no configurado.');
        }
        return $this->ledger;
    }

    /** @return array{string, string, string} */
    private function authority(Tenant $tenant, Branch $branch, User $actor, bool $allowed): array
    {
        $entity = $branch->legalEntity();
        if (!$allowed || !$actor->isActive() || $entity === null
            || $branch->tenant()->id() !== $tenant->id()
            || $entity->tenant()->id() !== $tenant->id()) {
            throw new DomainException('Acceso ficticio rechazado.');
        }
        return [
            'fixture:tenant/t'.substr(hash('sha256', $tenant->id()), 0, 22),
            'fixture:entity/e'.substr(hash('sha256', $tenant->id().'|'.$entity->id()), 0, 22),
            'fixture:actor/a'.substr(hash('sha256', $actor->id()), 0, 22),
        ];
    }

    private static function reference(string $kind, mixed $value): string
    {
        if (!is_string($value) || preg_match(
            '~^fixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}$~D',
            $value,
        ) !== 1) {
            throw new DomainException('Identificador ficticio obligatorio.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row
     * @param list<string> $names
     */
    private static function only(array $row, array $names): void
    {
        if (count($row) !== count($names) || array_diff(array_keys($row), $names) !== []) {
            throw new DomainException('Campo prohibido en datos ficticios.');
        }
    }

    private static function verify(mixed $row, string $tenantRef, string $entityRef, ?string $ref = null): void
    {
        if (!is_array($row)) {
            throw new DomainException('Registro sintético malformado.');
        }
        self::only($row, [
            'tenant_ref', 'entity_ref', 'affiliation_ref', 'employee_ref',
            'plan_ref', 'state', 'version',
        ]);
        foreach (['tenant', 'entity', 'affiliation', 'employee', 'plan'] as $kind) {
            self::reference($kind, $row[$kind.'_ref']);
        }
        if ($row['tenant_ref'] !== $tenantRef || $row['entity_ref'] !== $entityRef
            || ($ref !== null && $row['affiliation_ref'] !== $ref)
            || !in_array($row['state'], ['BORRADOR', 'EN_REVISION'], true)
            || !is_int($row['version']) || $row['version'] < 1) {
            throw new DomainException('Respuesta sintética ajena o no revisable.');
        }
    }
}

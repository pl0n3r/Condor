<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Optional synthetic-only port. Adapters MUST scope all reads and writes to
 * tenantRef; commits MUST atomically persist the row and its audit event,
 * with compare-and-swap on expectedVersion for state changes.
 * No live/personal-data adapter is provided by this construction-only slice.
 */
interface EmployeeApiPort
{
    /** @return list<array<string,mixed>> */
    public function list(string $tenantRef, string $actorRef): array;

    /** @return array<string,mixed>|null */
    public function view(string $tenantRef, string $employeeRef, string $actorRef): ?array;

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    public function createWithAudit(array $row, string $actorRef): array;

    /** @return array<string,mixed> */
    public function transitionWithAudit(
        string $tenantRef, string $employeeRef, string $state,
        int $expectedVersion, string $actorRef,
    ): array;

    public function auditRead(string $tenantRef, string $employeeRef, string $actorRef, string $action): void;
}

/** HTTP-facing facade: server-derived scope, synthetic refs, explicit review states. */
final readonly class EmployeeApiService
{
    public function __construct(private ?EmployeeApiPort $port = null)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listRecords(Tenant $tenant, Branch $branch, User $actor, bool $authorized): array
    {
        [$scope, $actorRef] = self::context($tenant, $branch, $actor, $authorized);
        $rows = $this->store()->list($scope, $actorRef);
        if (count($rows) > 100) {
            throw new DomainException('Consulta sintética excede el límite permitido.');
        }
        foreach ($rows as $row) {
            self::assertRow($row, $scope);
        }
        $this->store()->auditRead($scope, 'fixture:employee/list', $actorRef, 'hr.synthetic.listed');
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function viewRecord(
        Tenant $tenant, Branch $branch, User $actor, bool $authorized, string $slug,
    ): ?array {
        [$scope, $actorRef] = self::context($tenant, $branch, $actor, $authorized);
        $employeeRef = self::slug($slug);
        $row = $this->store()->view($scope, $employeeRef, $actorRef);
        if ($row !== null) {
            self::assertRow($row, $scope, $employeeRef);
        }
        $this->store()->auditRead($scope, $employeeRef, $actorRef, 'hr.synthetic.viewed');
        return $row;
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function createRecord(
        Tenant $tenant, Branch $branch, User $actor, bool $authorized, array $payload,
    ): array {
        self::only($payload, ['employee_ref', 'position_ref']);
        self::syntheticRef($payload['employee_ref'] ?? null, 'employee');
        self::syntheticRef($payload['position_ref'] ?? null, 'position');
        [$scope, $actorRef] = self::context($tenant, $branch, $actor, $authorized);
        $created = $this->store()->createWithAudit([
            'tenant_ref' => $scope,
            'employee_ref' => $payload['employee_ref'],
            'position_ref' => $payload['position_ref'],
            'state' => 'BORRADOR', 'version' => 1,
        ], $actorRef);
        self::assertRow($created, $scope, $payload['employee_ref']);
        if ($created['state'] !== 'BORRADOR' || $created['version'] !== 1) {
            throw new DomainException('La creación no puede aprobar o cerrar estados.');
        }
        return $created;
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function changeState(
        Tenant $tenant, Branch $branch, User $actor, bool $authorized,
        string $slug, array $payload,
    ): array {
        self::only($payload, ['state', 'expected_version']);
        $next = $payload['state'] ?? null;
        $version = $payload['expected_version'] ?? null;
        if (!in_array($next, ['BORRADOR', 'EN_REVISION'], true)
            || !is_int($version) || $version < 1 || $version > 1000000) {
            throw new DomainException('Transición sintética inválida.');
        }
        [$scope, $actorRef] = self::context($tenant, $branch, $actor, $authorized);
        $ref = self::slug($slug);
        $updated = $this->store()->transitionWithAudit($scope, $ref, $next, $version, $actorRef);
        self::assertRow($updated, $scope, $ref);
        if ($updated['state'] !== $next || $updated['version'] !== $version + 1) {
            throw new DomainException('Transición sin evidencia CAS válida.');
        }
        return $updated;
    }

    private function store(): EmployeeApiPort
    {
        if (!$this->port instanceof EmployeeApiPort) {
            throw new DomainException('Almacén sintético de RR. HH. no configurado.');
        }
        return $this->port;
    }

    /** @return array{string,string} */
    private static function context(Tenant $tenant, Branch $branch, User $actor, bool $authorized): array
    {
        $company = $branch->legalEntity();
        if (!$authorized || !$actor->isActive() || $company === null
            || $branch->tenant()->id() !== $tenant->id()
            || $company->tenant()->id() !== $tenant->id()) {
            throw new DomainException('Acceso sintético a RR. HH. denegado.');
        }
        return [
            'tenant-test-hr'.substr(hash('sha256', $tenant->id().'|'.$company->id()), 0, 24),
            'fixture:actor/'.substr(hash('sha256', $actor->id()), 0, 24),
        ];
    }

    private static function slug(string $value): string
    {
        $ref = 'fixture:employee/'.$value;
        self::syntheticRef($ref, 'employee');
        return $ref;
    }

    private static function syntheticRef(mixed $value, string $kind): void
    {
        if (!is_string($value)
            || preg_match('~^fixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}$~D', $value) !== 1) {
            throw new DomainException('Solo se permiten referencias ficticias tipadas.');
        }
    }

    /** @param array<string,mixed> $data
     *  @param list<string> $expected
     */
    private static function only(array $data, array $expected): void
    {
        if (count($data) !== count($expected)
            || array_diff(array_keys($data), $expected) !== []) {
            throw new DomainException('Campos inesperados en solicitud sintética.');
        }
    }

    /** @param array<string,mixed> $row */
    private static function assertRow(array $row, string $scope, ?string $ref = null): void
    {
        self::only($row, ['tenant_ref', 'employee_ref', 'position_ref', 'state', 'version']);
        self::syntheticRef($row['employee_ref'], 'employee');
        self::syntheticRef($row['position_ref'], 'position');
        if ($row['tenant_ref'] !== $scope || ($ref !== null && $row['employee_ref'] !== $ref)
            || !in_array($row['state'], ['BORRADOR', 'EN_REVISION'], true)
            || !is_int($row['version']) || $row['version'] < 1) {
            throw new DomainException('El adaptador devolvió un registro ajeno o inválido.');
        }
    }
}

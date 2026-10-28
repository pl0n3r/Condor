<?php

declare(strict_types=1);

namespace App\Application\Payroll;

use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * An optional, pre-live adapter. Each write MUST include its audit event in
 * one transaction, and state writes MUST compare the expected version.
 * Read operations are scoped by BOTH tenant and legal entity.
 */
interface PayrollApiPort
{
    /** @return array<string,mixed>|list<array<string,mixed>>|null */
    public function read(string $tenantRef, string $companyRef, ?string $recordRef): ?array;

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function write(string $action, array $data, ?int $expectedVersion, string $actorRef): array;

    public function logRead(
        string $tenantRef, string $companyRef, string $recordRef, string $actorRef, string $action,
    ): void;
}

/** Construction-only synthetic payroll orchestration; no real payroll adapter. */
final readonly class PayrollApiService
{
    public function __construct(private ?PayrollApiPort $adapter = null)
    {
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(
        Tenant $tenant, Branch $branch, User $actor, bool $authorized,
        string $command, array $input = [],
    ): array {
        [$tenantRef, $companyRef, $actorRef] = self::scope($tenant, $branch, $actor, $authorized);
        $adapter = $this->adapter;
        if ($adapter === null) {
            throw new DomainException('API de nómina ficticia sin almacén autorizado.');
        }

        if ($command === 'list') {
            self::fields($input, []);
            $found = $adapter->read($tenantRef, $companyRef, null) ?? [];
            if (!array_is_list($found) || count($found) > 100) {
                throw new DomainException('Resultado de nómina ficticia inválido.');
            }
            foreach ($found as $record) {
                self::record($record, $tenantRef, $companyRef);
            }
            $adapter->logRead($tenantRef, $companyRef, 'fixture:payroll/list', $actorRef, 'payroll.listed');
            return ['records' => $found];
        }

        if ($command === 'view') {
            self::fields($input, ['ref']);
            $ref = self::typed($input['ref'], 'payroll');
            $found = $adapter->read($tenantRef, $companyRef, $ref);
            if ($found !== null) {
                self::record($found, $tenantRef, $companyRef, $ref);
            }
            $adapter->logRead($tenantRef, $companyRef, $ref, $actorRef, 'payroll.viewed');
            return ['record' => $found];
        }

        if ($command === 'create') {
            self::fields($input, ['payroll_ref', 'employee_ref', 'period_ref']);
            $data = [
                'tenant_ref' => $tenantRef,
                'company_ref' => $companyRef,
                'payroll_ref' => self::typed($input['payroll_ref'], 'payroll'),
                'employee_ref' => self::typed($input['employee_ref'], 'employee'),
                'period_ref' => self::typed($input['period_ref'], 'period'),
                'state' => 'BORRADOR',
                'version' => 1,
            ];
            $created = $adapter->write('create', $data, null, $actorRef);
            self::record($created, $tenantRef, $companyRef, $data['payroll_ref']);
            if ($created['state'] !== 'BORRADOR' || $created['version'] !== 1) {
                throw new DomainException('Un borrador no puede cerrarse automáticamente.');
            }
            return ['record' => $created];
        }

        if ($command === 'state') {
            self::fields($input, ['ref', 'state', 'expected_version']);
            $ref = self::typed($input['ref'], 'payroll');
            $next = $input['state'];
            $version = $input['expected_version'];
            if (!in_array($next, ['BORRADOR', 'EN_REVISION'], true)
                || !is_int($version) || $version < 1 || $version > 1000000) {
                throw new DomainException('Estado de nómina ficticia inválido.');
            }
            $updated = $adapter->write('state', [
                'tenant_ref' => $tenantRef,
                'company_ref' => $companyRef,
                'payroll_ref' => $ref,
                'state' => $next,
            ], $version, $actorRef);
            self::record($updated, $tenantRef, $companyRef, $ref);
            if ($updated['state'] !== $next || $updated['version'] !== $version + 1) {
                throw new DomainException('Transición no atómica o versión incompatible.');
            }
            return ['record' => $updated];
        }

        throw new DomainException('Operación de nómina ficticia no disponible.');
    }

    /** @return array{string,string,string} */
    private static function scope(Tenant $tenant, Branch $branch, User $actor, bool $authorized): array
    {
        $entity = $branch->legalEntity();
        if (!$authorized || !$actor->isActive() || $entity === null
            || $branch->tenant()->id() !== $tenant->id()
            || $entity->tenant()->id() !== $tenant->id()) {
            throw new DomainException('Operación ficticia no autorizada.');
        }
        return [
            'fixture:tenant/t'.substr(hash('sha256', $tenant->id()), 0, 23),
            'fixture:company/c'.substr(hash('sha256', $tenant->id().'|'.$entity->id()), 0, 23),
            'fixture:actor/a'.substr(hash('sha256', $actor->id()), 0, 23),
        ];
    }

    private static function typed(mixed $value, string $kind): string
    {
        if (!is_string($value) || preg_match(
            '~^fixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}$~D',
            $value,
        ) !== 1) {
            throw new DomainException('Solo se admiten identificadores ficticios tipados.');
        }
        return $value;
    }

    /** @param array<string,mixed> $data
     * @param list<string> $names
     */
    private static function fields(array $data, array $names): void
    {
        if (count($data) !== count($names) || array_diff(array_keys($data), $names) !== []) {
            throw new DomainException('Campos de nómina ficticia no permitidos.');
        }
    }

    /** @param mixed $value */
    private static function record(
        mixed $value, string $tenantRef, string $companyRef, ?string $expectedRef = null,
    ): void {
        if (!is_array($value)) {
            throw new DomainException('Registro ficticio inválido.');
        }
        self::fields($value, [
            'tenant_ref', 'company_ref', 'payroll_ref', 'employee_ref',
            'period_ref', 'state', 'version',
        ]);
        foreach (['tenant', 'company', 'payroll', 'employee', 'period'] as $kind) {
            self::typed($value[$kind.'_ref'], $kind);
        }
        if ($value['tenant_ref'] !== $tenantRef || $value['company_ref'] !== $companyRef
            || ($expectedRef !== null && $value['payroll_ref'] !== $expectedRef)
            || !in_array($value['state'], ['BORRADOR', 'EN_REVISION'], true)
            || !is_int($value['version']) || $value['version'] < 1) {
            throw new DomainException('Almacén ficticio devolvió una empresa o estado ajenos.');
        }
    }
}

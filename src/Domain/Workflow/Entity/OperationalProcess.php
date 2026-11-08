<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Entity;

use App\Shared\Id\UlidFactory;
use DomainException;

/** Ledger inmutable de procesos internos por tenant y razón social. */
final class OperationalProcess
{
    private const KINDS = ['PROCESO', 'PROCEDIMIENTO', 'FLUJO', 'CONTROL'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
    private const TRANSITIONS = [
        'PENDIENTE' => ['EN_REVISION', 'REQUIERE_REVISION'],
        'EN_REVISION' => ['REQUIERE_REVISION'],
        'REQUIERE_REVISION' => ['EN_REVISION'],
    ];

    private static function isUlid(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) !== 26) {
            return false;
        }
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) === 1;
    }

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        if (!self::isUlid($tenant) || !self::isUlid($company) || !self::isUlid($actor)) {
            throw new DomainException('La identidad empresarial o el actor no son válidos.');
        }
    }

    public static function assertReference(string $ref): void
    {
        $opaque = preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $ref) === 1;
        $looksPersonal = preg_match('/[0-9]{7,}/', $ref) === 1;
        if (!$opaque || $looksPersonal) {
            throw new DomainException('Solo se admiten referencias de Procesos opacas.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!in_array($kind, self::KINDS, true)) {
            throw new DomainException('Proceso de Procesos no admitido.');
        }

        return [
            'id' => UlidFactory::new(),
            'tenant_id' => $tenant, 'company_id' => $company,
            'record_ref' => $ref, 'kind' => $kind,
            'state' => 'PENDIENTE', 'version' => 1, 'previous_id' => null,
            'actor_id' => $actor, 'occurred_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function inspect(array $record, string $tenant, string $company): array
    {
        if (($record['tenant_id'] ?? null) !== $tenant
            || ($record['company_id'] ?? null) !== $company) {
            throw new DomainException('Proceso ajeno al ámbito empresarial solicitado.');
        }
        foreach (['id', 'actor_id'] as $field) {
            if (!self::isUlid($record[$field] ?? null)) {
                throw new DomainException('Identificador de auditoría inválido.');
            }
        }
        self::assertReference((string) ($record['record_ref'] ?? ''));
        $kind = $record['kind'] ?? null;
        $state = $record['state'] ?? null;
        if (!in_array($kind, self::KINDS, true) || !in_array($state, self::STATES, true)) {
            throw new DomainException('Clasificación o estado de Procesos inválido.');
        }
        $rawVersion = $record['version'] ?? null;
        $version = filter_var($rawVersion, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($version === false || (string) $version !== (string) $rawVersion) {
            throw new DomainException('Secuencia de revisiones inválida.');
        }
        if (($record['previous_id'] ?? null) !== null && !self::isUlid($record['previous_id'])) {
            throw new DomainException('Referencia a la revisión anterior inválida.');
        }

        $record['version'] = $version;
        return $record;
    }

    /** @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    public static function revise(
        array $old, string $tenant, string $company, string $actor, string $next, int $version,
    ): array {
        $current = self::inspect($old, $tenant, $company);
        self::assertIdentity($tenant, $company, $actor);
        if ($current['version'] !== $version || $version >= 1000000
            || !in_array($next, self::TRANSITIONS[$current['state']] ?? [], true)) {
            throw new DomainException('Transición Procesos inválida o versión obsoleta.');
        }

        $current['previous_id'] = $current['id'];
        $current['id'] = UlidFactory::new();
        $current['version'] = $version + 1;
        $current['state'] = $next;
        $current['actor_id'] = $actor;
        $current['occurred_at'] = gmdate('Y-m-d H:i:s');
        return $current;
    }
}

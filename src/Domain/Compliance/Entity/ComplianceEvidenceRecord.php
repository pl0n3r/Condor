<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use App\Shared\Id\UlidFactory;
use DomainException;

/**
 * Registro opaco de evidencias de cumplimiento: sin identidades de empleados,
 * diagnósticos ni decisiones jurídicas automáticas.
 */
final class ComplianceEvidenceRecord
{
    private const KINDS = ['OBLIGACION', 'EVIDENCIA', 'REVISION', 'VENCIMIENTO'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
    private const TRANSITIONS = [
        'PENDIENTE' => ['EN_REVISION', 'REQUIERE_REVISION'],
        'EN_REVISION' => ['REQUIERE_REVISION'],
        'REQUIERE_REVISION' => ['EN_REVISION'],
    ];

    private static function isUlid(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $id) === 1;
    }

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        foreach ([$tenant, $company, $actor] as $id) {
            if (!self::isUlid($id)) {
                throw new DomainException('Identidad Cumplimiento legal inválida.');
            }
        }
    }

    public static function assertReference(string $ref): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) !== 1
            || preg_match('/\d{7,}/', $ref) === 1) {
            throw new DomainException('Solo se aceptan referencias Cumplimiento legal opacas.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!in_array($kind, self::KINDS, true)) {
            throw new DomainException('Categoría de revisión Cumplimiento legal no admitida.');
        }

        return [
            'id' => UlidFactory::new(),
            'tenant_id' => $tenant,
            'company_id' => $company,
            'record_ref' => $ref,
            'kind' => $kind,
            'state' => 'PENDIENTE',
            'version' => 1,
            'previous_id' => null,
            'actor_id' => $actor,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function inspect(array $record, string $tenant, string $company): array
    {
        foreach (['tenant_id' => $tenant, 'company_id' => $company] as $field => $expected) {
            if (($record[$field] ?? null) !== $expected) {
                throw new DomainException('Registro Cumplimiento legal fuera de ámbito.');
            }
        }
        if (!self::isUlid($record['id'] ?? null) || !self::isUlid($record['actor_id'] ?? null)) {
            throw new DomainException('Identidad de auditoría Cumplimiento legal inválida.');
        }
        self::assertReference((string) ($record['record_ref'] ?? ''));
        if (!in_array($record['kind'] ?? null, self::KINDS, true)
            || !in_array($record['state'] ?? null, self::STATES, true)) {
            throw new DomainException('Categoría o estado Cumplimiento legal no permitido.');
        }
        $raw = $record['version'] ?? null;
        $number = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($number === false || (string) $number !== (string) $raw) {
            throw new DomainException('Versión Cumplimiento legal inválida.');
        }
        $previous = $record['previous_id'] ?? null;
        if ($previous !== null && !self::isUlid($previous)) {
            throw new DomainException('Cadena de auditoría Cumplimiento legal inválida.');
        }

        $record['version'] = $number;
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
            throw new DomainException('Transición Cumplimiento legal inválida o versión obsoleta.');
        }

        return array_replace($current, [
            'id' => UlidFactory::new(),
            'state' => $next,
            'version' => $version + 1,
            'previous_id' => $current['id'],
            'actor_id' => $actor,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

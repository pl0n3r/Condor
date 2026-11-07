<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use App\Shared\Id\UlidFactory;
use DomainException;

/**
 * An opaque, audited occupational-safety review reference.
 * No diagnoses, employee identities, medical notes or automated approvals.
 */
final class OccupationalSafetyRecord
{
    private const KINDS = ['INSPECCION', 'CONTROL', 'CAPACITACION', 'EMERGENCIA'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    private static function validUlid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $value) === 1;
    }

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        if (!self::validUlid($tenant) || !self::validUlid($company) || !self::validUlid($actor)) {
            throw new DomainException('Identidad SG-SST inválida.');
        }
    }

    public static function assertReference(string $ref): void
    {
        $opaque = preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) === 1;
        if (!$opaque || preg_match('/[0-9]{7,}/', $ref) === 1) {
            throw new DomainException('Solo se aceptan referencias SG-SST opacas.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!in_array($kind, self::KINDS, true)) {
            throw new DomainException('Categoría de revisión SG-SST no admitida.');
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

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function inspect(array $row, string $tenant, string $company): array
    {
        if (($row['tenant_id'] ?? null) !== $tenant || ($row['company_id'] ?? null) !== $company) {
            throw new DomainException('Registro SG-SST fuera de ámbito.');
        }

        $actor = $row['actor_id'] ?? null;
        $id = $row['id'] ?? null;
        if (!is_string($actor) || !is_string($id)
            || !self::validUlid($actor) || !self::validUlid($id)) {
            throw new DomainException('Identidad de auditoría SG-SST inválida.');
        }
        self::assertReference((string) ($row['record_ref'] ?? ''));
        if (!in_array($row['kind'] ?? null, self::KINDS, true)
            || !in_array($row['state'] ?? null, self::STATES, true)) {
            throw new DomainException('Categoría o estado SG-SST no permitido.');
        }
        $rawVersion = $row['version'] ?? null;
        $version = is_numeric($rawVersion) ? (int) $rawVersion : 0;
        if ($version < 1 || $version > 1000000 || (string) $version !== (string) $rawVersion) {
            throw new DomainException('Versión SG-SST inválida.');
        }
        $previous = $row['previous_id'] ?? null;
        if ($previous !== null && (!is_string($previous) || !self::validUlid($previous))) {
            throw new DomainException('Cadena de auditoría SG-SST inválida.');
        }

        $row['version'] = $version;
        return $row;
    }

    /** @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    public static function revise(
        array $old, string $tenant, string $company, string $actor, string $next, int $version,
    ): array {
        $verified = self::inspect($old, $tenant, $company);
        self::assertIdentity($tenant, $company, $actor);
        $currentState = $verified['state'];
        $allowed = match ($currentState) {
            'PENDIENTE' => ['EN_REVISION', 'REQUIERE_REVISION'],
            'EN_REVISION' => ['REQUIERE_REVISION'],
            'REQUIERE_REVISION' => ['EN_REVISION'],
            default => [],
        };
        if ($verified['version'] !== $version || $version >= 1000000
            || !in_array($next, $allowed, true)) {
            throw new DomainException('Transición SG-SST inválida o versión obsoleta.');
        }

        return array_replace($verified, [
            'id' => UlidFactory::new(),
            'state' => $next,
            'version' => $version + 1,
            'previous_id' => $verified['id'],
            'actor_id' => $actor,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

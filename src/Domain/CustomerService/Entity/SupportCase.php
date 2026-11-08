<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\Entity;

use App\Shared\Id\UlidFactory;
use DomainException;

/** Inmutable: cada respuesta o revisión crea una nueva versión auditada del caso. */
final class SupportCase
{
    private const ALLOWED = [
        'PENDIENTE' => ['EN_REVISION', 'REQUIERE_REVISION'],
        'EN_REVISION' => ['REQUIERE_REVISION'],
        'REQUIERE_REVISION' => ['EN_REVISION'],
    ];

    private static function validUlid(mixed $candidate): bool
    {
        return is_string($candidate)
            && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $candidate) === 1;
    }

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        if (!self::validUlid($tenant) || !self::validUlid($company) || !self::validUlid($actor)) {
            throw new DomainException('Ámbito o responsable de atención inválido.');
        }
    }

    public static function assertReference(string $ref): void
    {
        $opaque = preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $ref) === 1;
        if (!$opaque || preg_match('/[0-9]{7,}/', $ref) === 1) {
            throw new DomainException('El caso requiere una referencia interna sin datos personales.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!in_array($kind, ['PQRS', 'GARANTIA', 'SOLICITUD'], true)) {
            throw new DomainException('Tipo de atención no reconocido.');
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
        $scopeMatches = ($row['tenant_id'] ?? null) === $tenant
            && ($row['company_id'] ?? null) === $company;
        $validChain = self::validUlid($row['id'] ?? null)
            && self::validUlid($row['actor_id'] ?? null)
            && (($row['previous_id'] ?? null) === null || self::validUlid($row['previous_id']));
        if (!$scopeMatches || !$validChain) {
            throw new DomainException('Caso de atención ajeno o auditoría inconsistente.');
        }

        self::assertReference((string) ($row['record_ref'] ?? ''));
        if (!in_array($row['kind'] ?? null, ['PQRS', 'GARANTIA', 'SOLICITUD'], true)
            || !array_key_exists((string) ($row['state'] ?? ''), self::ALLOWED)) {
            throw new DomainException('Estado o clasificación de servicio no válida.');
        }

        $raw = $row['version'] ?? null;
        $number = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if (!is_int($number) || (string) $number !== (string) $raw) {
            throw new DomainException('La secuencia de versiones del caso es inválida.');
        }
        $row['version'] = $number;
        return $row;
    }

    /** @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    public static function revise(
        array $old, string $tenant, string $company, string $actor, string $next, int $version,
    ): array {
        self::assertIdentity($tenant, $company, $actor);
        $case = self::inspect($old, $tenant, $company);
        $choices = self::ALLOWED[$case['state']];
        if ($case['version'] !== $version || $version >= 1000000
            || !in_array($next, $choices, true)) {
            throw new DomainException('La revisión solicitada está obsoleta o no está permitida.');
        }

        $case['previous_id'] = $case['id'];
        $case['id'] = UlidFactory::new();
        $case['version']++;
        $case['actor_id'] = $actor;
        $case['state'] = $next;
        $case['occurred_at'] = gmdate('Y-m-d H:i:s');

        return $case;
    }
}

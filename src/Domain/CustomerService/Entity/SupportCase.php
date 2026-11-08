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

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        $identifiers = [$tenant, $company, $actor];
        $invalid = array_filter($identifiers, static function (string $id): bool {
            return strlen($id) !== 26
                || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1;
        });
        if ($invalid !== []) {
            throw new DomainException('La atención requiere identidades de ámbito y actor válidas.');
        }
    }

    public static function assertReference(string $ref): void
    {
        $size = strlen($ref);
        $segments = preg_match('/[0-9]{7,}/', $ref);
        $syntax = preg_match('/^[a-z][a-z0-9_-]*$/D', $ref);
        if ($size < 1 || $size > 64 || $syntax !== 1 || $segments !== 0) {
            throw new DomainException('La referencia de servicio debe ser opaca y no personal.');
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
        $id = $row['id'] ?? '';
        $actor = $row['actor_id'] ?? '';
        $previous = $row['previous_id'] ?? null;
        $validChain = is_string($id) && is_string($actor)
            && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) === 1
            && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $actor) === 1
            && ($previous === null || (
                is_string($previous)
                && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $previous) === 1
            ));
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

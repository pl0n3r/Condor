<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Entity;

use App\Shared\Id\UlidFactory;
use DomainException;

/**
 * Registro de ejecución de un proceso empresarial. La transición se agrega
 * como revisión nueva; los eventos previos nunca se reescriben.
 */
final class OperationalProcess
{
    private const KINDS = [
        'PROCESO' => true, 'PROCEDIMIENTO' => true, 'FLUJO' => true, 'CONTROL' => true,
    ];

    private const NEXT_STATE = [
        'PENDIENTE' => ['EN_REVISION' => true, 'REQUIERE_REVISION' => true],
        'EN_REVISION' => ['REQUIERE_REVISION' => true],
        'REQUIERE_REVISION' => ['EN_REVISION' => true],
    ];

    private static function requireUlid(mixed $identity): void
    {
        if (!is_string($identity) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $identity) !== 1) {
            throw new DomainException('Identificador de proceso o actor inválido.');
        }
    }

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        foreach ([$tenant, $company, $actor] as $identity) {
            self::requireUlid($identity);
        }
    }

    public static function assertReference(string $ref): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $ref) !== 1
            || preg_match('/[0-9]{7,}/', $ref) === 1) {
            throw new DomainException('Identificador de proceso debe ser opaco, no personal.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!isset(self::KINDS[$kind])) {
            throw new DomainException('Categoría de proceso no autorizada.');
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
        if ($record['tenant_id'] !== $tenant || $record['company_id'] !== $company) {
            throw new DomainException('El proceso pertenece a otra razón social.');
        }
        self::requireUlid($record['id'] ?? null);
        self::requireUlid($record['actor_id'] ?? null);
        if (($record['previous_id'] ?? null) !== null) {
            self::requireUlid($record['previous_id']);
        }
        self::assertReference((string) ($record['record_ref'] ?? ''));
        $kind = $record['kind'] ?? null;
        $state = $record['state'] ?? null;
        if (!is_string($kind) || !isset(self::KINDS[$kind])
            || !is_string($state) || !array_key_exists($state, self::NEXT_STATE)) {
            throw new DomainException('Categoría o estado de proceso inválido.');
        }
        $raw = $record['version'] ?? null;
        $revision = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if (!is_int($revision) || (string) $revision !== (string) $raw) {
            throw new DomainException('Versión de proceso inconsistente.');
        }
        $record['version'] = $revision;

        return $record;
    }

    /** @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    public static function revise(
        array $old, string $tenant, string $company, string $actor, string $next, int $version,
    ): array {
        self::assertIdentity($tenant, $company, $actor);
        $current = self::inspect($old, $tenant, $company);
        $possible = self::NEXT_STATE[$current['state']];
        if ($version !== $current['version'] || $version >= 1000000 || !isset($possible[$next])) {
            throw new DomainException('La transición de proceso no es válida o su versión caducó.');
        }

        return array_replace($current, [
            'id' => UlidFactory::new(),
            'previous_id' => $current['id'],
            'version' => $version + 1,
            'state' => $next,
            'actor_id' => $actor,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

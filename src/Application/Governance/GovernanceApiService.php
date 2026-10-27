<?php

declare(strict_types=1);

namespace App\Application\Governance;

use DomainException;

/** An offline, synthetic-only, session-scoped governance API projection. */
final class GovernanceApiService
{
    private const MAX_ROWS = 100;
    private const STATES = ['REQUIERE_REVISION', 'EN_REVISION'];

    /**
     * @param array<string,mixed> $store
     * @return list<array{ref:string,kind:string,state:string}>
     */
    public static function listRecords(array $store, string $scope): array
    {
        self::assertScope($scope);
        $records = $store[$scope] ?? [];
        if (!is_array($records) || !array_is_list($records) || count($records) > self::MAX_ROWS) {
            throw new DomainException('Registro sintético inválido.');
        }
        $seen = [];
        $normalized = [];
        foreach ($records as $record) {
            if (!is_array($record) || !self::trustedRecord($record)) {
                throw new DomainException('Registro sintético inválido.');
            }
            $ref = (string) $record['ref'];
            if (isset($seen[$ref])) {
                throw new DomainException('Registro sintético duplicado.');
            }
            $seen[$ref] = true;
            $normalized[] = [
                'ref' => $ref,
                'kind' => (string) $record['kind'],
                'state' => (string) $record['state'],
            ];
        }
        usort($normalized, static fn (array $left, array $right): int => strcmp($left['ref'], $right['ref']));
        return $normalized;
    }

    /**
     * @param array<string,mixed> $store
     * @return array{ref:string,kind:string,state:string}|null
     */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        self::assertRef($ref);
        foreach (self::listRecords($store, $scope) as $record) {
            if ($record['ref'] === $ref) {
                return $record;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $payload
     * @return array{store:array<string,mixed>,record:array{ref:string,kind:string,state:string}}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        self::assertScope($scope);
        if (!self::exactKeys($payload, ['kind', 'ref'])
            || !is_string($payload['kind'])
            || !is_string($payload['ref'])
            || !self::validKindRef($payload['kind'], $payload['ref'])
        ) {
            throw new DomainException('Solo se admiten identificadores sintéticos.');
        }
        $records = self::listRecords($store, $scope);
        if (count($records) >= self::MAX_ROWS) {
            throw new DomainException('Límite de registros sintéticos alcanzado.');
        }
        foreach ($records as $record) {
            if ($record['ref'] === $payload['ref']) {
                throw new DomainException('Referencia sintética duplicada.');
            }
        }
        $created = [
            'ref' => $payload['ref'],
            'kind' => $payload['kind'],
            'state' => 'REQUIERE_REVISION',
        ];
        $records[] = $created;
        $store[$scope] = $records;
        return ['store' => $store, 'record' => $created];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{store:array<string,mixed>,record:array{ref:string,kind:string,state:string}}
     */
    public static function changeState(array $store, string $scope, string $ref, string $next): array
    {
        self::assertScope($scope);
        self::assertRef($ref);
        if (!in_array($next, self::STATES, true)) {
            throw new DomainException('La aprobación o cierre automático no está permitido.');
        }
        $records = self::listRecords($store, $scope);
        foreach ($records as $index => $record) {
            if ($record['ref'] === $ref) {
                $records[$index]['state'] = $next;
                $store[$scope] = $records;
                return ['store' => $store, 'record' => $records[$index]];
            }
        }
        throw new DomainException('Referencia sintética no encontrada.');
    }

    private static function assertScope(string $scope): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1) {
            throw new DomainException('Ámbito de empresa inválido.');
        }
    }

    private static function assertRef(string $ref): void
    {
        if (preg_match('~^fixture:(body|minute|power)/[a-z][a-z0-9_-]{0,47}$~D', $ref) !== 1) {
            throw new DomainException('Referencia sintética inválida.');
        }
    }

    private static function validKindRef(string $kind, string $ref): bool
    {
        return in_array($kind, ['body', 'minute', 'power'], true)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $ref) === 1;
    }

    private static function trustedRecord(mixed $value): bool
    {
        return is_array($value)
            && self::exactKeys($value, ['kind', 'ref', 'state'])
            && is_string($value['kind'])
            && is_string($value['ref'])
            && self::validKindRef($value['kind'], $value['ref'])
            && in_array($value['state'], self::STATES, true);
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $keys
     */
    private static function exactKeys(array $value, array $keys): bool
    {
        if (count($value) !== count($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }
}

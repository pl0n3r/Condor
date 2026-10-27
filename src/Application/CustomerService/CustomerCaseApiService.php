<?php

declare(strict_types=1);

namespace App\Application\CustomerService;

use DomainException;

/** In-memory synthetic case trial: no persistence, legal conclusion or external I/O. */
final class CustomerCaseApiService
{
    private const MAX_CASES = 100;
    private const TYPES = ['PQRS', 'RECLAMO', 'SOLICITUD'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    /**
     * @param array<string,mixed> $store
     * @return list<array{case_ref:string,type:string,state:string}>
     */
    public static function listRecords(array $store, string $scope): array
    {
        self::assertScope($scope);
        $records = $store[$scope] ?? [];
        if (!is_array($records) || !array_is_list($records) || count($records) > self::MAX_CASES) {
            throw new DomainException('Casos sintéticos inválidos.');
        }
        $out = [];
        $seen = [];
        foreach ($records as $record) {
            if (!self::validRecord($record)) {
                throw new DomainException('Caso sintético inválido.');
            }
            $ref = (string) $record['case_ref'];
            if (isset($seen[$ref])) {
                throw new DomainException('Referencia sintética duplicada.');
            }
            $seen[$ref] = true;
            $out[] = [
                'case_ref' => $ref,
                'type' => (string) $record['type'],
                'state' => (string) $record['state'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['case_ref'], $b['case_ref']));
        return $out;
    }

    /**
     * @param array<string,mixed> $store
     * @return array{case_ref:string,type:string,state:string}|null
     */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        self::assertRef($ref);
        foreach (self::listRecords($store, $scope) as $record) {
            if ($record['case_ref'] === $ref) {
                return $record;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $payload
     * @return array{store:array<string,mixed>,record:array{case_ref:string,type:string,state:string}}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        self::assertScope($scope);
        if (!self::exactKeys($payload, ['case_ref', 'type'])
            || !is_string($payload['case_ref']) || !is_string($payload['type'])
            || !self::isRef($payload['case_ref']) || !in_array($payload['type'], self::TYPES, true)) {
            throw new DomainException('Solo se permiten referencias sintéticas de casos.');
        }
        $records = self::listRecords($store, $scope);
        if (count($records) >= self::MAX_CASES) {
            throw new DomainException('Límite de casos sintéticos alcanzado.');
        }
        foreach ($records as $record) {
            if ($record['case_ref'] === $payload['case_ref']) {
                throw new DomainException('Referencia sintética duplicada.');
            }
        }
        $created = ['case_ref' => $payload['case_ref'], 'type' => $payload['type'], 'state' => 'PENDIENTE'];
        $records[] = $created;
        $store[$scope] = $records;
        return ['store' => $store, 'record' => $created];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{store:array<string,mixed>,record:array{case_ref:string,type:string,state:string}}
     */
    public static function changeState(array $store, string $scope, string $ref, string $next): array
    {
        self::assertScope($scope);
        self::assertRef($ref);
        // No approval, fulfillment or closure: even explicit close is deferred to a future gated slice.
        if (!in_array($next, ['EN_REVISION', 'REQUIERE_REVISION'], true)) {
            throw new DomainException('La aprobación o cierre no están permitidos.');
        }
        $records = self::listRecords($store, $scope);
        foreach ($records as $i => $record) {
            if ($record['case_ref'] === $ref) {
                $records[$i]['state'] = $next;
                $store[$scope] = $records;
                return ['store' => $store, 'record' => $records[$i]];
            }
        }
        throw new DomainException('Caso sintético no encontrado.');
    }

    private static function assertScope(string $scope): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1) {
            throw new DomainException('Ámbito inválido.');
        }
    }

    private static function isRef(string $ref): bool
    {
        return preg_match('~^fixture:case/[a-z][a-z0-9_-]{0,47}$~D', $ref) === 1;
    }

    private static function assertRef(string $ref): void
    {
        if (!self::isRef($ref)) {
            throw new DomainException('Referencia sintética inválida.');
        }
    }

    /**
     * @param array<array-key,mixed> $data
     * @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys)
            && array_diff_key(array_flip($keys), $data) === [];
    }

    private static function validRecord(mixed $record): bool
    {
        return is_array($record)
            && self::exactKeys($record, ['case_ref', 'type', 'state'])
            && is_string($record['case_ref']) && self::isRef($record['case_ref'])
            && is_string($record['type']) && in_array($record['type'], self::TYPES, true)
            && is_string($record['state']) && in_array($record['state'], self::STATES, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Compliance;

use DomainException;

/** Synthetic-only compliance evidence queue: no legal decision, DB or external I/O. */
final class ComplianceApiService
{
    private const MAX_RECORDS = 100;
    private const TYPES = ['EVIDENCIA', 'EVALUACION'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    /**
     * @param array<string, mixed> $store
     * @return list<array{evidence_ref:string, obligation_ref:string, type:string, state:string}>
     */
    public static function listRecords(array $store, string $scope): array
    {
        self::assertScope($scope);
        $records = $store[$scope] ?? [];
        if (!is_array($records) || !array_is_list($records) || count($records) > self::MAX_RECORDS) {
            throw new DomainException('Evidencias sintéticas inválidas.');
        }
        $indexed = [];
        foreach ($records as $item) {
            if (!self::validRecord($item)) {
                throw new DomainException('Evidencia sintética inválida.');
            }
            $key = (string) $item['evidence_ref'];
            if (isset($indexed[$key])) {
                throw new DomainException('Referencia sintética duplicada.');
            }
            $indexed[$key] = [
                'evidence_ref' => $key,
                'obligation_ref' => (string) $item['obligation_ref'],
                'type' => (string) $item['type'],
                'state' => (string) $item['state'],
            ];
        }
        ksort($indexed, SORT_STRING);
        return array_values($indexed);
    }

    /**
     * @param array<string, mixed> $store
     * @return array{evidence_ref:string, obligation_ref:string, type:string, state:string}|null
     */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        self::assertRef($ref, 'evidence');
        foreach (self::listRecords($store, $scope) as $record) {
            if ($record['evidence_ref'] === $ref) {
                return $record;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $payload
     * @return array{store:array<string,mixed>, record:array{evidence_ref:string, obligation_ref:string, type:string, state:string}}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        self::assertScope($scope);
        if (!self::exactKeys($payload, ['evidence_ref', 'obligation_ref', 'type'])
            || !self::isRef($payload['evidence_ref'], 'evidence')
            || !self::isRef($payload['obligation_ref'], 'obligation')
            || !is_string($payload['type'])
            || !in_array($payload['type'], self::TYPES, true)
        ) {
            throw new DomainException('Solo se admiten referencias ficticias de cumplimiento.');
        }
        $records = self::listRecords($store, $scope);
        if (count($records) >= self::MAX_RECORDS) {
            throw new DomainException('Límite de evidencias ficticias alcanzado.');
        }
        foreach ($records as $record) {
            if ($record['evidence_ref'] === $payload['evidence_ref']) {
                throw new DomainException('Referencia sintética duplicada.');
            }
        }
        $created = [
            'evidence_ref' => $payload['evidence_ref'],
            'obligation_ref' => $payload['obligation_ref'],
            'type' => $payload['type'],
            'state' => 'PENDIENTE',
        ];
        $records[] = $created;
        $store[$scope] = $records;
        return ['store' => $store, 'record' => $created];
    }

    /**
     * @param array<string, mixed> $store
     * @return array{store:array<string,mixed>, record:array{evidence_ref:string, obligation_ref:string, type:string, state:string}}
     */
    public static function changeState(array $store, string $scope, string $ref, string $next): array
    {
        self::assertScope($scope);
        self::assertRef($ref, 'evidence');
        // Explicit review transitions only. No approval, rejection or legal closure.
        if (!in_array($next, ['EN_REVISION', 'REQUIERE_REVISION'], true)) {
            throw new DomainException('No se permiten aprobación ni cierre automáticos.');
        }
        $records = self::listRecords($store, $scope);
        foreach ($records as $index => $record) {
            if ($record['evidence_ref'] !== $ref) {
                continue;
            }
            $record['state'] = $next;
            $records[$index] = $record;
            $store[$scope] = $records;
            return ['store' => $store, 'record' => $record];
        }
        throw new DomainException('Evidencia sintética no encontrada.');
    }

    private static function assertScope(string $scope): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scope) !== 1) {
            throw new DomainException('Ámbito ficticio inválido.');
        }
    }

    private static function isRef(mixed $ref, string $type): bool
    {
        return is_string($ref)
            && preg_match('~\Afixture:'.preg_quote($type, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $ref) === 1;
    }

    private static function assertRef(string $ref, string $type): void
    {
        if (!self::isRef($ref, $type)) {
            throw new DomainException('Referencia ficticia inválida.');
        }
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private static function exactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys)
            && array_diff_key(array_flip($keys), $data) === [];
    }

    private static function validRecord(mixed $item): bool
    {
        return is_array($item)
            && self::exactKeys($item, ['evidence_ref', 'obligation_ref', 'type', 'state'])
            && self::isRef($item['evidence_ref'], 'evidence')
            && self::isRef($item['obligation_ref'], 'obligation')
            && is_string($item['type']) && in_array($item['type'], self::TYPES, true)
            && is_string($item['state']) && in_array($item['state'], self::STATES, true);
    }
}

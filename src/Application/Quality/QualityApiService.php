<?php

declare(strict_types=1);

namespace App\Application\Quality;

use DomainException;

/**
 * M13: reversible, session-only quality review queue. Every reference is fake.
 * No customer data, external IO, certification, approval or automatic closure.
 */
final class QualityApiService
{
    private const MAX_ITEMS = 50;
    private const KINDS = ['NO_CONFORMIDAD', 'ACCION_CORRECTIVA', 'AUDITORIA_INTERNA'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    /** @param array<string,mixed> $store @return list<array<string,string>> */
    public static function listRecords(array $store, string $scope): array
    {
        $items = self::bucket($store, $scope);
        ksort($items, SORT_STRING);
        return array_values($items);
    }

    /** @param array<string,mixed> $store @return array<string,string>|null */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        return self::bucket($store, $scope)[self::reference($ref, 'quality-record')] ?? null;
    }

    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $payload
     * @return array{store:array<string,mixed>,record:array<string,string>}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['kind', 'record_ref', 'source_ref']) {
            throw new DomainException('Campos ficticios inesperados.');
        }
        $recordRef = self::reference($payload['record_ref'], 'quality-record');
        $sourceRef = self::reference($payload['source_ref'], 'quality-source');
        $kind = $payload['kind'];
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new DomainException('Tipo ficticio no admitido.');
        }
        $bucket = self::bucket($store, $scope);
        if (isset($bucket[$recordRef]) || count($bucket) >= self::MAX_ITEMS) {
            throw new DomainException('Referencia duplicada o cupo de pruebas alcanzado.');
        }
        $record = [
            'record_ref' => $recordRef,
            'source_ref' => $sourceRef,
            'kind' => $kind,
            'state' => 'PENDIENTE',
        ];
        $bucket[$recordRef] = $record;
        $store[$scope] = $bucket;
        return ['store' => $store, 'record' => $record];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{store:array<string,mixed>,record:array<string,string>}
     */
    public static function changeState(array $store, string $scope, string $ref, string $next): array
    {
        $recordRef = self::reference($ref, 'quality-record');
        if (!in_array($next, ['EN_REVISION', 'REQUIERE_REVISION'], true)) {
            throw new DomainException('No hay cierre ni aprobación automática.');
        }
        $bucket = self::bucket($store, $scope);
        if (!isset($bucket[$recordRef])) {
            throw new DomainException('Registro ficticio inexistente.');
        }
        if ($bucket[$recordRef]['state'] === $next) {
            return ['store' => $store, 'record' => $bucket[$recordRef]];
        }
        $bucket[$recordRef]['state'] = $next;
        $store[$scope] = $bucket;
        return ['store' => $store, 'record' => $bucket[$recordRef]];
    }

    /** @param array<string,mixed> $store @return array<string,array<string,string>> */
    private static function bucket(array $store, string $scope): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scope) !== 1) {
            throw new DomainException('Ámbito empresarial inválido.');
        }
        $raw = $store[$scope] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== []) || count($raw) > self::MAX_ITEMS) {
            throw new DomainException('Cola de demostración inválida.');
        }
        $clean = [];
        foreach ($raw as $ref => $row) {
            if (!is_array($row) || count($row) !== 4) {
                throw new DomainException('Registro de demostración inválido.');
            }
            $recordRef = self::reference($row['record_ref'] ?? null, 'quality-record');
            $sourceRef = self::reference($row['source_ref'] ?? null, 'quality-source');
            if ($ref !== $recordRef || !is_string($row['kind'] ?? null)
                || !in_array($row['kind'], self::KINDS, true)
                || !is_string($row['state'] ?? null)
                || !in_array($row['state'], self::STATES, true)
            ) {
                throw new DomainException('Registro de demostración inconsistente.');
            }
            $clean[$recordRef] = [
                'record_ref' => $recordRef,
                'source_ref' => $sourceRef,
                'kind' => $row['kind'],
                'state' => $row['state'],
            ];
        }
        return $clean;
    }

    private static function reference(mixed $value, string $kind): string
    {
        if (!is_string($value)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,31}\z~D', $value) !== 1
            || preg_match('/[0-9]{8,}/', $value) === 1
        ) {
            throw new DomainException('Solo se permiten referencias ficticias.');
        }
        return $value;
    }
}

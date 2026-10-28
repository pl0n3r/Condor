<?php

declare(strict_types=1);

namespace App\Application\Compliance;

use DomainException;

/**
 * Synthetic-only SG-SST HTTP projection. No persistence, remote calls or legal certification.
 * Session data must be scoped with the authenticated tenant AND branch on every request.
 */
final class OccupationalApiService
{
    private const LIMIT = 100;
    private const KINDS = ['PELIGRO', 'INSPECCION', 'INCIDENTE', 'EPP', 'CAPACITACION', 'EMERGENCIA'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    /**
     * @param array<string,mixed> $store
     * @return list<array<string,mixed>>
     */
    public static function listRecords(array $store, string $scope): array
    {
        $items = self::bucket($store, $scope);
        ksort($items, SORT_STRING);
        return array_values($items);
    }

    /**
     * @param array<string,mixed> $store
     * @return array<string,mixed>|null
     */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        $recordRef = self::reference($ref, 'sgsst-record');
        return self::bucket($store, $scope)[$recordRef] ?? null;
    }

    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $payload
     * @return array{store:array<string,mixed>,record:array<string,mixed>}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        self::exactKeys($payload, ['record_ref', 'source_ref', 'kind']);
        $ref = self::reference($payload['record_ref'], 'sgsst-record');
        $source = self::reference($payload['source_ref'], 'sgsst-source');
        $kind = $payload['kind'];
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new DomainException('Tipo de prueba SG-SST inválido.');
        }

        $items = self::bucket($store, $scope);
        if (isset($items[$ref]) || count($items) >= self::LIMIT) {
            throw new DomainException('Referencia SG-SST duplicada o límite alcanzado.');
        }

        $record = [
            'record_ref' => $ref,
            'source_ref' => $source,
            'kind' => $kind,
            'state' => 'PENDIENTE',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'automatically_approved' => false,
            'automatically_closed' => false,
        ];
        $items[$ref] = $record;
        $store[$scope] = $items;
        return ['store' => $store, 'record' => $record];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{store:array<string,mixed>,record:array<string,mixed>}
     */
    public static function changeState(array $store, string $scope, string $ref, string $next): array
    {
        $recordRef = self::reference($ref, 'sgsst-record');
        if (!in_array($next, ['EN_REVISION', 'REQUIERE_REVISION'], true)) {
            throw new DomainException('No se permiten aprobación, certificación ni cierre automático.');
        }

        $items = self::bucket($store, $scope);
        if (!isset($items[$recordRef])) {
            throw new DomainException('Registro SG-SST ficticio no encontrado.');
        }
        $items[$recordRef]['state'] = $next;
        $store[$scope] = $items;
        return ['store' => $store, 'record' => $items[$recordRef]];
    }

    /**
     * @param array<string,mixed> $store
     * @return array<string,array<string,mixed>>
     */
    private static function bucket(array $store, string $scope): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scope) !== 1) {
            throw new DomainException('Contexto SG-SST inválido.');
        }
        $raw = $store[$scope] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== []) || count($raw) > self::LIMIT) {
            throw new DomainException('Archivo sintético SG-SST inválido.');
        }

        $items = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                throw new DomainException('Registro de prueba SG-SST inválido.');
            }
            self::exactKeys($value, [
                'record_ref', 'source_ref', 'kind', 'state', 'synthetic_only',
                'requires_professional_review', 'automatically_approved', 'automatically_closed',
            ]);
            $ref = self::reference($value['record_ref'], 'sgsst-record');
            $source = self::reference($value['source_ref'], 'sgsst-source');
            if ($ref !== $key || !is_string($value['kind'])
                || !in_array($value['kind'], self::KINDS, true)
                || !is_string($value['state']) || !in_array($value['state'], self::STATES, true)
                || $value['synthetic_only'] !== true || $value['requires_professional_review'] !== true
                || $value['automatically_approved'] !== false || $value['automatically_closed'] !== false
            ) {
                throw new DomainException('Registro SG-SST no autorizado.');
            }
            $items[$ref] = [
                'record_ref' => $ref, 'source_ref' => $source,
                'kind' => $value['kind'], 'state' => $value['state'],
                'synthetic_only' => true, 'requires_professional_review' => true,
                'automatically_approved' => false, 'automatically_closed' => false,
            ];
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new DomainException('Solo se permiten campos sintéticos del contrato SG-SST.');
        }
    }

    private static function reference(mixed $value, string $kind): string
    {
        if (!is_string($value)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $value) !== 1
            || preg_match('/[0-9]{7,}/', $value) === 1
        ) {
            throw new DomainException('Solo se admiten referencias SG-SST ficticias.');
        }
        return $value;
    }
}

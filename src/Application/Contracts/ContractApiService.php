<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use DomainException;

/**
 * Inert contract API preview. Every value is a synthetic reference, held only
 * inside the current server-side session; no legal or worker data is retained.
 */
final class ContractApiService
{
    private const MAX_RECORDS = 100;
    private const REVIEW_STATES = ['REQUIERE_REVISION', 'EN_REVISION'];
    private const KINDS = ['template', 'contract', 'addendum'];

    /**
     * @param array<string, mixed> $session
     * @return list<array<string, mixed>>
     */
    public static function listRecords(array $session, string $scope): array
    {
        return array_values(self::records($session, $scope));
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    public static function viewRecord(array $session, string $scope, string $ref): ?array
    {
        self::reference($ref);
        return self::records($session, $scope)[$ref] ?? null;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     * @return array{store: array<string,mixed>, record: array<string,mixed>}
     */
    public static function createRecord(array $session, string $scope, array $payload): array
    {
        if (!self::keys($payload, ['kind', 'ref'])
            || !is_string($payload['ref']) || !is_string($payload['kind'])
            || !self::validReference($payload['ref'], $payload['kind'])
        ) {
            throw new DomainException('Solo se admiten referencias de contratación ficticias.');
        }
        $items = self::records($session, $scope);
        if (count($items) >= self::MAX_RECORDS || array_key_exists($payload['ref'], $items)) {
            throw new DomainException('Contrato ficticio duplicado o capacidad agotada.');
        }
        $record = [
            'ref' => $payload['ref'],
            'kind' => $payload['kind'],
            'state' => 'REQUIERE_REVISION',
            'synthetic_only' => true,
        ];
        $items[$record['ref']] = $record;
        $session[$scope] = $items;
        return ['store' => $session, 'record' => $record];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{store: array<string,mixed>, record: array<string,mixed>}
     */
    public static function changeState(array $session, string $scope, string $ref, string $state): array
    {
        self::reference($ref);
        if (!in_array($state, self::REVIEW_STATES, true)) {
            throw new DomainException('No se permite aprobar, finalizar ni cerrar automáticamente.');
        }
        $items = self::records($session, $scope);
        if (!array_key_exists($ref, $items)) {
            throw new DomainException('Contrato ficticio no encontrado.');
        }
        $items[$ref]['state'] = $state;
        $session[$scope] = $items;
        return ['store' => $session, 'record' => $items[$ref]];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, array<string, mixed>>
     */
    private static function records(array $session, string $scope): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scope) !== 1) {
            throw new DomainException('Ámbito empresarial inválido.');
        }
        $records = $session[$scope] ?? [];
        if (!is_array($records) || count($records) > self::MAX_RECORDS) {
            throw new DomainException('Almacén ficticio inválido.');
        }
        foreach ($records as $key => $row) {
            if (!is_string($key) || !is_array($row)
                || !self::keys($row, ['kind', 'ref', 'state', 'synthetic_only'])
                || !is_string($row['kind']) || !is_string($row['ref'])
                || $key !== $row['ref']
                || !self::validReference($key, $row['kind'])
                || !in_array($row['state'], self::REVIEW_STATES, true)
                || $row['synthetic_only'] !== true
            ) {
                throw new DomainException('Registro ficticio no confiable.');
            }
        }
        ksort($records);
        return $records;
    }

    private static function reference(string $ref): void
    {
        if (preg_match('~\Afixture:(template|contract|addendum)/[a-z][a-z0-9_-]{0,47}\z~D', $ref) !== 1
            || preg_match('/[0-9]{7,}/', $ref) === 1
        ) {
            throw new DomainException('Referencia ficticia inválida.');
        }
    }

    private static function validReference(string $ref, string $kind): bool
    {
        if (!in_array($kind, self::KINDS, true)) {
            return false;
        }
        try {
            self::reference($ref);
        } catch (DomainException) {
            return false;
        }
        return str_starts_with($ref, 'fixture:'.$kind.'/');
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $expected
     */
    private static function keys(array $value, array $expected): bool
    {
        return count($value) === count($expected)
            && array_diff($expected, array_keys($value)) === [];
    }
}

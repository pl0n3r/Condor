<?php

declare(strict_types=1);

namespace App\Application\Finance;

use DomainException;

/**
 * In-memory financial demo queue. All identifiers are synthetic fixtures;
 * no bank, tax, payment or accounting transaction is performed.
 */
final class FinanceApiService
{
    private const MAX_RECORDS = 100;
    private const KINDS = ['INGRESO', 'EGRESO'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    /** @param array<string,mixed> $store
     *  @return list<array<string,string>>
     */
    public static function listRecords(array $store, string $scope): array
    {
        $records = self::bucket($store, $scope);
        ksort($records, SORT_STRING);
        return array_values($records);
    }

    /** @param array<string,mixed> $store
     *  @return array<string,string>|null
     */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        return self::bucket($store, $scope)[self::fixture($ref, 'entry')] ?? null;
    }

    /** @param array<string,mixed> $store
     *  @param array<string,mixed> $payload
     *  @return array{store:array<string,mixed>,record:array<string,string>}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['amount_ref', 'concept_ref', 'entry_ref', 'kind']) {
            throw new DomainException('Campos de demostración financiera inesperados.');
        }

        $ref = self::fixture($payload['entry_ref'], 'entry');
        $concept = self::fixture($payload['concept_ref'], 'concept');
        $amount = self::fixture($payload['amount_ref'], 'amount');
        $kind = $payload['kind'];
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new DomainException('Tipo de registro ficticio inválido.');
        }

        $records = self::bucket($store, $scope);
        if (isset($records[$ref]) || count($records) >= self::MAX_RECORDS) {
            throw new DomainException('Registro de prueba duplicado o límite alcanzado.');
        }
        $record = [
            'entry_ref' => $ref,
            'concept_ref' => $concept,
            'amount_ref' => $amount,
            'kind' => $kind,
            'state' => 'PENDIENTE',
        ];
        $records[$ref] = $record;
        $store[$scope] = $records;
        return ['store' => $store, 'record' => $record];
    }

    /** @param array<string,mixed> $store
     *  @return array{store:array<string,mixed>,record:array<string,string>}
     */
    public static function changeState(array $store, string $scope, string $ref, string $state): array
    {
        $entry = self::fixture($ref, 'entry');
        if (!in_array($state, ['EN_REVISION', 'REQUIERE_REVISION'], true)) {
            throw new DomainException('Aprobaciones, pagos y cierres no están habilitados.');
        }
        $records = self::bucket($store, $scope);
        if (!isset($records[$entry])) {
            throw new DomainException('Registro ficticio no encontrado.');
        }
        $records[$entry]['state'] = $state;
        $store[$scope] = $records;
        return ['store' => $store, 'record' => $records[$entry]];
    }

    /** @param array<string,mixed> $store
     *  @return array<string,array<string,string>>
     */
    private static function bucket(array $store, string $scope): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $scope) !== 1) {
            throw new DomainException('Contexto empresarial inválido.');
        }
        $records = $store[$scope] ?? [];
        if (!is_array($records) || (array_is_list($records) && $records !== [])
            || count($records) > self::MAX_RECORDS
        ) {
            throw new DomainException('Archivo financiero sintético inválido.');
        }
        $valid = [];
        foreach ($records as $key => $item) {
            if (!is_array($item) || count($item) !== 5) {
                throw new DomainException('Registro financiero ficticio inválido.');
            }
            $entry = self::fixture($item['entry_ref'] ?? null, 'entry');
            $concept = self::fixture($item['concept_ref'] ?? null, 'concept');
            $amount = self::fixture($item['amount_ref'] ?? null, 'amount');
            if ($key !== $entry || !is_string($item['kind'] ?? null)
                || !in_array($item['kind'], self::KINDS, true)
                || !is_string($item['state'] ?? null)
                || !in_array($item['state'], self::STATES, true)
            ) {
                throw new DomainException('Registro financiero ficticio inconsistente.');
            }
            $valid[$entry] = [
                'entry_ref' => $entry, 'concept_ref' => $concept,
                'amount_ref' => $amount, 'kind' => $item['kind'],
                'state' => $item['state'],
            ];
        }
        return $valid;
    }

    private static function fixture(mixed $value, string $kind): string
    {
        if (!is_string($value)
            || preg_match('~\Afixture:finance-'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~', $value) !== 1
        ) {
            throw new DomainException('Solo se permiten referencias financieras ficticias.');
        }
        return $value;
    }
}

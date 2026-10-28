<?php

declare(strict_types=1);

namespace App\Application\Compliance;

use DomainException;

/** Review queue for synthetic compliance evidence. Nothing here decides legal compliance. */
final class ComplianceApiService
{
    private const LIMIT = 100;
    private const CATEGORIES = ['EVIDENCIA', 'EVALUACION'];
    private const REVIEW_STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];

    /**
     * @param array<string, mixed> $store
     * @return list<array{evidence_ref:string,obligation_ref:string,type:string,state:string}>
     */
    public static function listRecords(array $store, string $scope): array
    {
        $items = self::bucket($store, $scope);
        ksort($items, SORT_STRING);
        return array_values($items);
    }

    /**
     * @param array<string, mixed> $store
     * @return array{evidence_ref:string,obligation_ref:string,type:string,state:string}|null
     */
    public static function viewRecord(array $store, string $scope, string $ref): ?array
    {
        $reference = self::reference($ref, 'evidence');
        return self::bucket($store, $scope)[$reference] ?? null;
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $payload
     * @return array{store:array<string,mixed>,record:array{evidence_ref:string,obligation_ref:string,type:string,state:string}}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        $fields = array_keys($payload);
        sort($fields, SORT_STRING);
        if ($fields !== ['evidence_ref', 'obligation_ref', 'type']) {
            throw new DomainException('Campos de demostración inesperados.');
        }
        $evidence = self::reference($payload['evidence_ref'], 'evidence');
        $obligation = self::reference($payload['obligation_ref'], 'obligation');
        $category = $payload['type'];
        if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
            throw new DomainException('Tipo de evidencia sintética inválido.');
        }
        $items = self::bucket($store, $scope);
        if (isset($items[$evidence]) || count($items) >= self::LIMIT) {
            throw new DomainException('Referencia duplicada o límite sintético alcanzado.');
        }
        $record = [
            'evidence_ref' => $evidence,
            'obligation_ref' => $obligation,
            'type' => $category,
            'state' => 'PENDIENTE',
        ];
        $items[$evidence] = $record;
        $store[$scope] = $items;
        return ['store' => $store, 'record' => $record];
    }

    /**
     * @param array<string, mixed> $store
     * @return array{store:array<string,mixed>,record:array{evidence_ref:string,obligation_ref:string,type:string,state:string}}
     */
    public static function changeState(array $store, string $scope, string $ref, string $next): array
    {
        $evidence = self::reference($ref, 'evidence');
        // Never approve, certify, finalize or close compliance automatically.
        if (!in_array($next, ['EN_REVISION', 'REQUIERE_REVISION'], true)) {
            throw new DomainException('La aprobación o cierre no están habilitados.');
        }
        $items = self::bucket($store, $scope);
        if (!isset($items[$evidence])) {
            throw new DomainException('Evidencia ficticia no encontrada.');
        }
        $items[$evidence]['state'] = $next;
        $store[$scope] = $items;
        return ['store' => $store, 'record' => $items[$evidence]];
    }

    /**
     * @param array<string,mixed> $store
     * @return array<string,array{evidence_ref:string,obligation_ref:string,type:string,state:string}>
     */
    private static function bucket(array $store, string $scope): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $scope) !== 1) {
            throw new DomainException('Contexto empresarial inválido.');
        }
        $raw = $store[$scope] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== []) || count($raw) > self::LIMIT) {
            throw new DomainException('Almacén sintético inválido.');
        }
        $result = [];
        foreach ($raw as $key => $value) {
            if (!is_array($value) || count($value) !== 4) {
                throw new DomainException('Registro de prueba inválido.');
            }
            $ref = self::reference($value['evidence_ref'] ?? null, 'evidence');
            if ($ref !== $key) {
                throw new DomainException('Índice de evidencia inconsistente.');
            }
            $obligation = self::reference($value['obligation_ref'] ?? null, 'obligation');
            $type = $value['type'] ?? null;
            $state = $value['state'] ?? null;
            if (!is_string($type) || !in_array($type, self::CATEGORIES, true)
                || !is_string($state) || !in_array($state, self::REVIEW_STATES, true)
            ) {
                throw new DomainException('Estado o tipo de prueba inválido.');
            }
            $result[$ref] = [
                'evidence_ref' => $ref, 'obligation_ref' => $obligation,
                'type' => $type, 'state' => $state,
            ];
        }
        return $result;
    }

    private static function reference(mixed $candidate, string $kind): string
    {
        if (!is_string($candidate)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~', $candidate) !== 1
        ) {
            throw new DomainException('Solo se admiten identificadores de pruebas ficticias.');
        }
        return $candidate;
    }
}

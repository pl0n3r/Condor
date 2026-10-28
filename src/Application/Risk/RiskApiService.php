<?php

declare(strict_types=1);

namespace App\Application\Risk;

use DomainException;

/**
 * Construction-only, tenant-and-branch-scoped risk queue.
 * Synthetic session snapshots are not a production risk register.
 */
final class RiskApiService
{
    private const LIMIT = 50;
    private const CATEGORIES = ['operativo', 'financiero', 'legal', 'tecnologico'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
    private const NEXT_STATES = ['EN_REVISION', 'REQUIERE_REVISION'];

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
        return self::bucket($store, $scope)[self::reference($ref)] ?? null;
    }

    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $payload
     * @return array{store:array<string,mixed>,record:array<string,mixed>}
     */
    public static function createRecord(array $store, string $scope, array $payload): array
    {
        $fields = array_keys($payload);
        sort($fields, SORT_STRING);
        if ($fields !== ['category', 'impact', 'probability', 'risk_ref']) {
            throw new DomainException('Campos de prueba inesperados.');
        }

        $ref = self::reference($payload['risk_ref']);
        $category = self::category($payload['category']);
        $probability = self::score($payload['probability']);
        $impact = self::score($payload['impact']);
        $items = self::bucket($store, $scope);
        if (isset($items[$ref]) || count($items) >= self::LIMIT) {
            throw new DomainException('Riesgo ficticio duplicado o límite alcanzado.');
        }

        $record = [
            'risk_ref' => $ref,
            'category' => $category,
            'probability' => $probability,
            'impact' => $impact,
            'state' => 'PENDIENTE',
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
        $ref = self::reference($ref);
        if (!in_array($next, self::NEXT_STATES, true)) {
            throw new DomainException('No se permite aprobar ni cerrar riesgos.');
        }

        $items = self::bucket($store, $scope);
        if (!isset($items[$ref])) {
            throw new DomainException('Riesgo ficticio no encontrado.');
        }
        if ($items[$ref]['state'] === $next) {
            throw new DomainException('El riesgo ya tiene ese estado.');
        }

        $items[$ref]['state'] = $next;
        $store[$scope] = $items;

        return ['store' => $store, 'record' => $items[$ref]];
    }

    /**
     * @param array<string,mixed> $store
     * @return array<string,array<string,mixed>>
     */
    private static function bucket(array $store, string $scope): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scope) !== 1) {
            throw new DomainException('Contexto empresarial inválido.');
        }
        $raw = $store[$scope] ?? [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || count($raw) > self::LIMIT) {
            throw new DomainException('Archivo de demostración inválido.');
        }

        $result = [];
        foreach ($raw as $key => $value) {
            if (!is_array($value) || count($value) !== 5) {
                throw new DomainException('Registro de demostración inválido.');
            }
            $ref = self::reference($value['risk_ref'] ?? null);
            if ($ref !== $key || !isset($value['state'])
                || !is_string($value['state']) || !in_array($value['state'], self::STATES, true)
            ) {
                throw new DomainException('Estado o índice ficticio inválido.');
            }
            $result[$ref] = [
                'risk_ref' => $ref,
                'category' => self::category($value['category'] ?? null),
                'probability' => self::score($value['probability'] ?? null),
                'impact' => self::score($value['impact'] ?? null),
                'state' => $value['state'],
            ];
        }

        return $result;
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('~\Afixture:risk/[a-z][a-z0-9_-]{0,47}\z~D', $value) !== 1
            || preg_match('/[0-9]{7,}/', $value) === 1
        ) {
            throw new DomainException('Solo se admiten referencias sintéticas de riesgos.');
        }

        return $value;
    }

    private static function category(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::CATEGORIES, true)) {
            throw new DomainException('Categoría ficticia inválida.');
        }
        return $value;
    }

    private static function score(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 5) {
            throw new DomainException('Puntuación sintética inválida.');
        }
        return $value;
    }
}

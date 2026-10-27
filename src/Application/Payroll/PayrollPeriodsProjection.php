<?php

declare(strict_types=1);

namespace App\Application\Payroll;

/**
 * M04/S01–S04: pure synthetic payroll evidence, never a legal settlement.
 * Exact decimals are converted to integer hundredths without floats.
 */
final class PayrollPeriodsProjection
{
    private const MAX_ROWS = 100;
    private const SCOPED = ['tenant_ref', 'legal_entity_ref'];
    private const COLLECTIONS = ['periods', 'concepts', 'novelties', 'hours'];
    private const FORBIDDEN = '/(?:email|e_mail|correo|phone|telefono|teléfono|celular|mobile|documento|document|cedula|cédula|nit|passport|identidad|identity|nombre|apellido|address|dirección|direccion|contacto|employee_name|person_name)/iu';

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function project(array $input): array
    {
        $tenant = self::ref($input['tenant_ref'] ?? null, 'tenant');
        $entity = self::ref($input['legal_entity_ref'] ?? null, 'entity');
        $result = [
            'tenant_ref' => $tenant,
            'legal_entity_ref' => $entity,
            'state' => 'REQUIERE_REVISION',
            'legal_effect' => false,
            'automatic_approval' => false,
            'periods' => [], 'concepts' => [], 'novelties' => [], 'hours' => [],
            'errors' => [],
        ];
        if ($tenant === null || $entity === null || !self::exactKeys($input, [
            'tenant_ref', 'legal_entity_ref', 'periods', 'concepts', 'novelties', 'hours',
        ]) || self::containsPersonalData($input)) {
            $result['errors'] = ['INVALID_SYNTHETIC_INPUT'];
            return $result;
        }
        foreach (self::COLLECTIONS as $group) {
            $list = $input[$group];
            if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_ROWS) {
                $result['errors'] = ['INVALID_SYNTHETIC_INPUT'];
                return $result;
            }
        }
        $periodRefs = [];
        $conceptRefs = [];
        $seen = [];
        foreach (self::COLLECTIONS as $group) {
            foreach ($input[$group] as $item) {
                if (!is_array($item) || !self::scopeMatches($item, $tenant, $entity)) {
                    $result['errors'] = ['INVALID_SCOPE_OR_ROW'];
                    return self::withoutRows($result);
                }
                $refField = match ($group) {
                    'periods' => 'period_ref', 'concepts' => 'concept_ref',
                    'novelties' => 'novelty_ref', default => 'hours_ref',
                };
                $ref = self::ref($item[$refField] ?? null, match ($group) {
                    'periods' => 'period', 'concepts' => 'concept',
                    'novelties' => 'novelty', default => 'hours',
                });
                if ($ref === null || isset($seen[$group][$ref])) {
                    $result['errors'] = ['INVALID_OR_DUPLICATE_REFERENCE'];
                    return self::withoutRows($result);
                }
                $seen[$group][$ref] = true;
                if ($group === 'periods') {
                    if (!self::exactKeys($item, [...self::SCOPED, 'period_ref', 'start_on', 'end_on'])
                        || !self::date($item['start_on']) || !self::date($item['end_on'])
                        || $item['start_on'] > $item['end_on']
                    ) {
                        $result['errors'] = ['INVALID_PERIOD'];
                        return self::withoutRows($result);
                    }
                    $periodRefs[$ref] = true;
                    $result['periods'][] = [
                        'period_ref' => $ref, 'start_on' => $item['start_on'],
                        'end_on' => $item['end_on'], 'state' => 'REQUIERE_REVISION',
                    ];
                } elseif ($group === 'concepts') {
                    $minor = self::hundredths($item['amount_decimal'] ?? null);
                    if (!self::exactKeys($item, [...self::SCOPED, 'concept_ref', 'version', 'kind', 'amount_decimal'])
                        || self::ref($item['version'], 'version') === null
                        || !in_array($item['kind'], ['devengo', 'deduccion'], true)
                        || $minor === null
                    ) {
                        $result['errors'] = ['INVALID_CONCEPT'];
                        return self::withoutRows($result);
                    }
                    $conceptRefs[$ref] = true;
                    $result['concepts'][] = [
                        'concept_ref' => $ref, 'version' => $item['version'], 'kind' => $item['kind'],
                        'amount_decimal' => $item['amount_decimal'], 'amount_hundredths' => $minor,
                        'state' => 'REQUIERE_REVISION',
                    ];
                } elseif ($group === 'novelties') {
                    $quantity = self::hundredths($item['quantity_decimal'] ?? null);
                    if (!self::exactKeys($item, [...self::SCOPED, 'novelty_ref', 'period_ref', 'concept_ref', 'quantity_decimal'])
                        || self::ref($item['period_ref'], 'period') === null
                        || self::ref($item['concept_ref'], 'concept') === null
                        || $quantity === null
                    ) {
                        $result['errors'] = ['INVALID_NOVELTY'];
                        return self::withoutRows($result);
                    }
                    $result['novelties'][] = [
                        'novelty_ref' => $ref, 'period_ref' => $item['period_ref'],
                        'concept_ref' => $item['concept_ref'], 'quantity_decimal' => $item['quantity_decimal'],
                        'quantity_hundredths' => $quantity, 'state' => 'REQUIERE_REVISION',
                    ];
                } else {
                    $value = self::hundredths($item['hours_decimal'] ?? null);
                    if (!self::exactKeys($item, [...self::SCOPED, 'hours_ref', 'period_ref', 'hours_decimal'])
                        || self::ref($item['period_ref'], 'period') === null || $value === null
                    ) {
                        $result['errors'] = ['INVALID_HOURS'];
                        return self::withoutRows($result);
                    }
                    $result['hours'][] = [
                        'hours_ref' => $ref, 'period_ref' => $item['period_ref'],
                        'hours_decimal' => $item['hours_decimal'], 'hours_hundredths' => $value,
                        'state' => 'REQUIERE_REVISION',
                    ];
                }
            }
        }
        foreach ($result['novelties'] as $novelty) {
            if (!isset($periodRefs[$novelty['period_ref']]) || !isset($conceptRefs[$novelty['concept_ref']])) {
                $result['errors'] = ['UNKNOWN_PERIOD_OR_CONCEPT'];
                return self::withoutRows($result);
            }
        }
        foreach ($result['hours'] as $hours) {
            if (!isset($periodRefs[$hours['period_ref']])) {
                $result['errors'] = ['UNKNOWN_PERIOD'];
                return self::withoutRows($result);
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function withoutRows(array $result): array
    {
        foreach (self::COLLECTIONS as $group) {
            $result[$group] = [];
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function scopeMatches(array $row, string $tenant, string $entity): bool
    {
        return ($row['tenant_ref'] ?? null) === $tenant
            && ($row['legal_entity_ref'] ?? null) === $entity;
    }

    private static function ref(mixed $value, string $kind): ?string
    {
        if (!is_string($value) || strlen($value) > 96) {
            return null;
        }
        return preg_match('~^fixture:'.preg_quote($kind, '~').'/[a-z0-9][a-z0-9_-]{0,55}$~D', $value) === 1
            ? $value : null;
    }

    private static function date(mixed $date): bool
    {
        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private static function hundredths(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $value) !== 1) {
            return null;
        }
        [$whole, $fraction] = explode('.', $value, 2);
        return ((int) $whole * 100) + (int) $fraction;
    }

    /**
     * @param array<string,mixed> $item
     * @param list<string> $keys
     */
    private static function exactKeys(array $item, array $keys): bool
    {
        if (count($item) !== count($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item)) {
                return false;
            }
        }
        return true;
    }

    private static function containsPersonalData(mixed $input): bool
    {
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                if ((is_string($key) && preg_match(self::FORBIDDEN, $key) === 1)
                    || self::containsPersonalData($value)
                ) {
                    return true;
                }
            }
            return false;
        }
        if (!is_string($input)) {
            return false;
        }
        return str_contains($input, '@')
            || preg_match('/(?:\+?57[ .-]?)?3[0-9]{9}/', $input) === 1
            || preg_match('/\b[0-9]{7,12}\b/', $input) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

/**
 * Disposable, synthetic-only organisational chart. No legal, employment,
 * permission or state-transition decisions, no I/O or personal information.
 */
final class OrganizationStructureProjection
{
    private const MAX_ROWS = 100;

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function project(array $data): array
    {
        $invalid = self::empty();
        if (!self::keys($data, ['tenant_ref', 'company_ref', 'as_of', 'areas', 'sites', 'positions'])
            || !self::tenant($data['tenant_ref'])
            || !self::ref($data['company_ref'], 'company')
            || !self::date($data['as_of'])
        ) {
            return $invalid;
        }
        $tenant = $data['tenant_ref'];
        $company = $data['company_ref'];
        $lookup = [];
        $collections = [];
        foreach (['areas', 'sites', 'positions'] as $group) {
            $rows = $data[$group];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) {
                return $invalid;
            }
            $seen = [];
            $collections[$group] = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !self::tenant($row['tenant_ref'] ?? null)
                    || !self::ref($row['company_ref'] ?? null, 'company')) {
                    return $invalid;
                }
                if ($row['tenant_ref'] !== $tenant || $row['company_ref'] !== $company) {
                    continue;
                }
                if (!self::validRow($group, $row) || isset($seen[$row['ref']])) {
                    return $invalid;
                }
                $seen[$row['ref']] = true;
                $collections[$group][] = $row;
            }
            usort($collections[$group], static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
            $lookup[$group] = array_column($collections[$group], null, 'ref');
        }
        foreach ($collections['areas'] as $area) {
            if ($area['parent_ref'] !== null && !isset($lookup['areas'][$area['parent_ref']])) {
                return $invalid;
            }
        }
        foreach ($collections['positions'] as $position) {
            if (!isset($lookup['areas'][$position['area_ref']])
                || !isset($lookup['sites'][$position['site_ref']])
                || ($position['parent_ref'] !== null && !isset($lookup['positions'][$position['parent_ref']]))) {
                return $invalid;
            }
        }
        $cycles = [];
        foreach (['areas', 'positions'] as $group) {
            foreach ($collections[$group] as $row) {
                $current = $row['ref'];
                $visited = [];
                while ($current !== null) {
                    if (isset($visited[$current])) {
                        $cycles[$group][$row['ref']] = true;
                        break;
                    }
                    $visited[$current] = true;
                    $current = $lookup[$group][$current]['parent_ref'] ?? null;
                }
            }
        }
        $areas = [];
        foreach ($collections['areas'] as $row) {
            $areas[] = ['ref' => $row['ref'], 'parent_ref' => $row['parent_ref'],
                'cycle_detected' => isset($cycles['areas'][$row['ref']]),
                'state' => 'REQUIERE_REVISION'];
        }
        $sites = array_map(static fn (array $row): array => [
            'ref' => $row['ref'], 'state' => 'REQUIERE_REVISION',
        ], $collections['sites']);
        $positions = [];
        foreach ($collections['positions'] as $row) {
            $positions[] = [
                'ref' => $row['ref'], 'area_ref' => $row['area_ref'],
                'site_ref' => $row['site_ref'], 'parent_ref' => $row['parent_ref'],
                'employee_ref' => $row['employee_ref'],
                'without_responsible' => $row['employee_ref'] === null,
                'cycle_detected' => isset($cycles['positions'][$row['ref']]),
                'state' => 'REQUIERE_REVISION',
            ];
        }
        return [
            'valid' => true, 'tenant_ref' => $tenant, 'company_ref' => $company,
            'state' => 'REQUIERE_REVISION', 'can_approve' => false, 'can_close' => false,
            'has_cycles' => $cycles !== [],
            'areas' => $areas, 'sites' => $sites, 'positions' => $positions,
        ];
    }

    /** @return array<string,mixed> */
    private static function empty(): array
    {
        return ['valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'state' => 'REQUIERE_REVISION', 'can_approve' => false,
            'can_close' => false, 'has_cycles' => false,
            'areas' => [], 'sites' => [], 'positions' => []];
    }

    /** @param array<string,mixed> $row */
    private static function validRow(string $group, array $row): bool
    {
        $schema = match ($group) {
            'areas' => ['tenant_ref', 'company_ref', 'ref', 'parent_ref'],
            'sites' => ['tenant_ref', 'company_ref', 'ref'],
            'positions' => ['tenant_ref', 'company_ref', 'ref', 'area_ref',
                'site_ref', 'parent_ref', 'employee_ref'],
            default => [],
        };
        if (!self::keys($row, $schema)) {
            return false;
        }
        $kind = match ($group) {
            'areas' => 'area', 'sites' => 'site', 'positions' => 'position', default => '',
        };
        if (!self::ref($row['ref'], $kind)) {
            return false;
        }
        if ($group === 'areas' || $group === 'positions') {
            if ($row['parent_ref'] !== null && !self::ref($row['parent_ref'], $kind)) {
                return false;
            }
        }
        if ($group === 'positions') {
            return self::ref($row['area_ref'], 'area')
                && self::ref($row['site_ref'], 'site')
                && ($row['employee_ref'] === null || self::ref($row['employee_ref'], 'employee'));
        }
        return true;
    }

    private static function tenant(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    private static function ref(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    /** @param array<string,mixed> $row @param list<string> $schema */
    private static function keys(array $row, array $schema): bool
    {
        $keys = array_keys($row);
        sort($keys);
        sort($schema);
        return $keys === $schema;
    }
}

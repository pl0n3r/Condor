<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

/** Offline synthetic-only requests: no persistence, diagnoses or approvals. */
final class LeaveAbsenceProjection
{
    private const MAX_ROWS = 100;

    /** @param array<string,mixed> $data
     *  @return array<string,mixed>
     */
    public static function project(array $data): array
    {
        $invalid = self::empty();
        if (!self::keys($data, ['tenant_ref', 'company_ref', 'as_of', 'requests', 'balances'])
            || !self::synthetic($data['tenant_ref'], 'tenant')
            || !self::synthetic($data['company_ref'], 'company')
            || !self::synthetic($data['as_of'], 'date')
            || !is_array($data['requests']) || !array_is_list($data['requests'])
            || !is_array($data['balances']) || !array_is_list($data['balances'])
            || count($data['requests']) > self::MAX_ROWS
            || count($data['balances']) > self::MAX_ROWS) {
            return $invalid;
        }
        $tenant = $data['tenant_ref'];
        $company = $data['company_ref'];
        $requests = [];
        $balances = [];
        foreach ($data['balances'] as $row) {
            if (!is_array($row) || !self::keys($row, ['tenant_ref', 'company_ref', 'employee_ref', 'days'])
                || !self::synthetic($row['tenant_ref'], 'tenant') || !self::synthetic($row['company_ref'], 'company')
                || !self::synthetic($row['employee_ref'], 'employee') || !is_int($row['days'])
                || $row['days'] < 0 || $row['days'] > 365) {
                return $invalid;
            }
            if ($row['tenant_ref'] !== $tenant || $row['company_ref'] !== $company) {
                continue;
            }
            if (isset($balances[$row['employee_ref']])) {
                return $invalid;
            }
            $balances[$row['employee_ref']] = $row['days'];
        }
        ksort($balances);
        $seen = [];
        foreach ($data['requests'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'tenant_ref', 'company_ref', 'ref', 'employee_ref', 'kind', 'starts_on', 'ends_on', 'state',
            ]) || !self::synthetic($row['tenant_ref'], 'tenant') || !self::synthetic($row['company_ref'], 'company')
                || !self::synthetic($row['ref'], 'request') || !self::synthetic($row['employee_ref'], 'employee')
                || !in_array($row['kind'], ['PERMISO', 'VACACIONES', 'INCAPACIDAD'], true)
                || !in_array($row['state'], ['PENDIENTE', 'EN_REVISION'], true)
                || !self::synthetic($row['starts_on'], 'date') || !self::synthetic($row['ends_on'], 'date')
                || strcmp($row['starts_on'], $row['ends_on']) > 0) {
                return $invalid;
            }
            if ($row['tenant_ref'] !== $tenant || $row['company_ref'] !== $company) {
                continue;
            }
            if (isset($seen[$row['ref']])) {
                return $invalid;
            }
            $seen[$row['ref']] = true;
            $requests[] = $row;
        }
        usort($requests, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        $result = [];
        foreach ($requests as $row) {
            $days = (int) ((strtotime($row['ends_on'] . ' UTC') - strtotime($row['starts_on'] . ' UTC')) / 86400) + 1;
            $overlap = false;
            foreach ($requests as $other) {
                if ($other['ref'] !== $row['ref'] && $other['employee_ref'] === $row['employee_ref']
                    && $other['starts_on'] <= $row['ends_on'] && $row['starts_on'] <= $other['ends_on']) {
                    $overlap = true;
                }
            }
            $available = $balances[$row['employee_ref']] ?? null;
            $result[] = [
                'ref' => $row['ref'], 'employee_ref' => $row['employee_ref'],
                'kind' => $row['kind'], 'state' => $row['state'],
                'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'],
                'requested_days' => $days, 'overlaps' => $overlap,
                'synthetic_balance_days' => $available,
                'insufficient_balance' => $row['kind'] === 'VACACIONES'
                    && $available !== null && $days > $available,
                'automatically_approved' => false, 'automatically_closed' => false,
            ];
        }
        return [
            'valid' => true, 'tenant_ref' => $tenant, 'company_ref' => $company,
            'state' => 'REQUIERE_REVISION', 'automatically_approved' => false,
            'automatically_closed' => false, 'requests' => $result,
        ];
    }

    /** @return array<string,mixed> */
    private static function empty(): array
    {
        return ['valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'state' => 'REQUIERE_REVISION', 'automatically_approved' => false,
            'automatically_closed' => false, 'requests' => []];
    }

    /** All identifiers are synthetic; unknown formats fail closed. */
    private static function synthetic(mixed $value, string $kind): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if ($kind === 'tenant') {
            return preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
        }
        if ($kind === 'date') {
            $shape = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $value);
            if ($shape !== 1) {
                return false;
            }
            $values = array_map('intval', explode('-', $value));
            return checkdate($values[1], $values[2], $values[0]);
        }
        return preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /** @param array<string,mixed> $row
     *  @param list<string> $schema
     */
    private static function keys(array $row, array $schema): bool
    {
        $names = array_keys($row);
        return count($names) === count($schema)
            && array_diff($schema, $names) === []
            && array_diff($names, $schema) === [];
    }
}

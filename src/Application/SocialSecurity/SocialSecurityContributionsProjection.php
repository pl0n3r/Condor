<?php

declare(strict_types=1);

namespace App\Application\SocialSecurity;

use InvalidArgumentException;

/** M05 synthetic-only; example rates have NO legal validity or operational effect. */
final class SocialSecurityContributionsProjection
{
    private const MAX_ROWS = 100;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function project(array $input): array
    {
        self::keys($input, ['tenant_ref', 'policy', 'affiliations', 'novelties']);
        $tenant = self::ref($input['tenant_ref'] ?? null, 'tenant');
        $policy = $input['policy'] ?? null;
        if (!is_array($policy)) {
            throw new InvalidArgumentException('Fixture policy required');
        }
        self::keys($policy, ['version', 'health_bp', 'pension_bp']);
        self::ref($policy['version'] ?? null, 'policy');
        foreach (['health_bp', 'pension_bp'] as $rate) {
            if (!isset($policy[$rate]) || !is_int($policy[$rate]) || $policy[$rate] < 0 || $policy[$rate] > 10000) {
                throw new InvalidArgumentException('Invalid synthetic rate');
            }
        }
        $affiliations = $input['affiliations'] ?? null;
        $novelties = $input['novelties'] ?? null;
        if (!is_array($affiliations) || !array_is_list($affiliations) || count($affiliations) > self::MAX_ROWS
            || !is_array($novelties) || !array_is_list($novelties) || count($novelties) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Invalid fixture collections');
        }
        $rows = [];
        $seen = [];
        foreach ($affiliations as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid fixture row');
            }
            self::keys($row, ['tenant_ref', 'employee_ref', 'affiliation_ref', 'base_units']);
            $scope = self::ref($row['tenant_ref'] ?? null, 'tenant');
            $employee = self::ref($row['employee_ref'] ?? null, 'employee');
            $affiliation = self::ref($row['affiliation_ref'] ?? null, 'affiliation');
            $base = $row['base_units'] ?? null;
            if (!is_int($base) || $base < 0 || $base > 10000000) {
                throw new InvalidArgumentException('Invalid synthetic base');
            }
            if ($scope !== $tenant) {
                continue;
            }
            if (isset($seen[$affiliation])) {
                throw new InvalidArgumentException('Duplicate affiliation');
            }
            $seen[$affiliation] = true;
            $rows[$employee] = [
                'employee_ref' => $employee,
                'affiliation_ref' => $affiliation,
                'health_units' => intdiv($base * $policy['health_bp'], 10000),
                'pension_units' => intdiv($base * $policy['pension_bp'], 10000),
                'novelties' => [],
                'state' => 'EN_REVISION',
            ];
        }
        foreach ($novelties as $novelty) {
            if (!is_array($novelty)) {
                throw new InvalidArgumentException('Invalid synthetic novelty');
            }
            self::keys($novelty, ['tenant_ref', 'employee_ref', 'novelty_ref']);
            $scope = self::ref($novelty['tenant_ref'] ?? null, 'tenant');
            $employee = self::ref($novelty['employee_ref'] ?? null, 'employee');
            $reference = self::ref($novelty['novelty_ref'] ?? null, 'novelty');
            if ($scope !== $tenant) {
                continue;
            }
            if (!isset($rows[$employee])) {
                throw new InvalidArgumentException('Orphan novelty');
            }
            if (in_array($reference, $rows[$employee]['novelties'], true)) {
                throw new InvalidArgumentException('Duplicate novelty');
            }
            $rows[$employee]['novelties'][] = $reference;
        }
        ksort($rows, SORT_STRING);
        foreach ($rows as &$row) {
            sort($row['novelties'], SORT_STRING);
        }
        unset($row);
        return [
            'tenant_ref' => $tenant,
            'policy_version' => $policy['version'],
            'rows' => array_values($rows),
            'state' => 'REQUIERE_REVISION',
            'legal_value' => false,
            'enabled_for_real_data' => false,
        ];
    }

    /** @param array<string,mixed> $data @param list<string> $required */
    private static function keys(array $data, array $required): void
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $expected = $required;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Non-fixture fields or missing required fields');
        }
    }

    private static function ref(mixed $value, string $kind): string
    {
        if (!is_string($value) || preg_match('~^fixture:' . $kind . '/[a-z][a-z0-9_-]{0,47}$~D', $value) !== 1) {
            throw new InvalidArgumentException('Expected synthetic fixture reference');
        }
        return $value;
    }
}

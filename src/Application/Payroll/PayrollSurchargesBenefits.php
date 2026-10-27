<?php

declare(strict_types=1);

namespace App\Application\Payroll;

/**
 * Deterministic, synthetic-only payroll preview. This is NOT a payslip,
 * payroll settlement or statement of Colombian labour law.
 * All calculation rates are supplied by the caller's versioned fixture.
 */
final class PayrollSurchargesBenefits
{
    private const MAX_ITEMS = 100;
    private const MAX_BASE_CENTS = 999999999999;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function project(array $input): array
    {
        $invalid = [
            'valid' => false,
            'state' => 'REQUIERE_REVISION',
            'tenant_ref' => null,
            'company_ref' => null,
            'policy_ref' => null,
            'slips' => [],
            'error' => 'invalid_synthetic_fixture',
        ];
        if (!self::keys($input, ['as_of', 'company_ref', 'items', 'parameters', 'tenant_ref'])
            || !self::tenant($input['tenant_ref'])
            || !self::fixture($input['company_ref'], 'company')
            || !self::date($input['as_of'])
            || !self::listOfRows($input['items'])
        ) {
            return $invalid;
        }

        $parameters = $input['parameters'];
        if (!is_array($parameters)
            || !self::keys($parameters, ['effective_from', 'effective_until', 'rates', 'version_ref'])
            || !self::fixture($parameters['version_ref'], 'policy')
            || !self::date($parameters['effective_from'])
            || !self::date($parameters['effective_until'])
            || $parameters['effective_from'] > $parameters['effective_until']
            || $input['as_of'] < $parameters['effective_from']
            || $input['as_of'] > $parameters['effective_until']
            || !is_array($parameters['rates'])
            || !self::keys($parameters['rates'], ['benefit_bp', 'deduction_bp', 'surcharge_bp'])
        ) {
            return $invalid;
        }
        $rates = $parameters['rates'];
        foreach ($rates as $name => $rate) {
            if (!is_int($rate) || $rate < 0
                || $rate > ($name === 'deduction_bp' ? 10000 : 20000)) {
                return $invalid;
            }
        }

        $seen = [];
        $slips = [];
        foreach ($input['items'] as $item) {
            if (!is_array($item)
                || !self::keys($item, ['base_cents', 'ref'])
                || !self::fixture($item['ref'], 'payroll')
                || !self::cents($item['base_cents'])
                || isset($seen[$item['ref']])
            ) {
                return $invalid;
            }
            $seen[$item['ref']] = true;
            $base = (int) $item['base_cents'];
            $surcharge = self::applyRate($base, $rates['surcharge_bp']);
            $deduction = self::applyRate($base, $rates['deduction_bp']);
            $benefit = self::applyRate($base, $rates['benefit_bp']);
            $slips[] = [
                'ref' => $item['ref'],
                'policy_ref' => $parameters['version_ref'],
                'base' => self::money($base),
                'surcharges' => self::money($surcharge),
                'deductions' => self::money($deduction),
                'benefits' => self::money($benefit),
                'net_preview' => self::money($base + $surcharge - $deduction),
                'state' => 'REQUIERE_REVISION',
                'requires_professional_review' => true,
                'official' => false,
                'watermark' => 'NO OFICIAL - DATOS SINTETICOS',
            ];
        }
        usort($slips, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        return [
            'valid' => true,
            'state' => 'REQUIERE_REVISION',
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
            'policy_ref' => $parameters['version_ref'],
            'slips' => $slips,
            'error' => null,
        ];
    }

    private static function applyRate(int $cents, int $bp): int
    {
        // Basis points (1 / 10000): exact integer arithmetic, round half up.
        return intdiv($cents * $bp + 5000, 10000);
    }

    private static function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function cents(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]{0,11})$/D', $value) === 1
            && (int) $value <= self::MAX_BASE_CENTS;
    }

    private static function tenant(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    private static function fixture(mixed $value, string $kind): bool
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

    private static function listOfRows(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= self::MAX_ITEMS;
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $expected
     */
    private static function keys(array $value, array $expected): bool
    {
        if (count($value) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }
}

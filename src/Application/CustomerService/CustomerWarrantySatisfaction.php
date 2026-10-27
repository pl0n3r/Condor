<?php

declare(strict_types=1);

namespace App\Application\CustomerService;

/**
 * M16: immutable, synthetic-only customer-service projection.
 * No persistence, transmission, legal determination, approval or closure.
 */
final class CustomerWarrantySatisfaction
{
    private const MAX_RECORDS = 100;
    private const WARRANTY_KEYS = ['tenant_ref', 'order_ref', 'warranty_ref', 'starts_on', 'ends_on'];
    private const COMMUNICATION_KEYS = ['tenant_ref', 'order_ref', 'template_ref'];
    private const SURVEY_KEYS = ['tenant_ref', 'survey_ref', 'period', 'score'];

    /**
     * @param array<string,mixed> $input Untrusted synthetic fixtures only.
     * @return array<string,mixed>
     */
    public static function project(array $input, string $referenceDate): array
    {
        $tenant = $input['tenant_ref'] ?? null;
        $result = self::emptyResult(self::tenant($tenant) ? $tenant : null);
        if (!self::keys($input, ['tenant_ref', 'warranties', 'communications', 'surveys'])
            || !self::tenant($tenant)
            || self::date($referenceDate) === null
            || !self::syntheticTree($input)) {
            return $result;
        }
        foreach (['warranties', 'communications', 'surveys'] as $key) {
            if (!is_array($input[$key]) || !array_is_list($input[$key])
                || count($input[$key]) > self::MAX_RECORDS) {
                return $result;
            }
        }

        $warranties = [];
        $communications = [];
        $totals = [];
        $seenWarranties = [];
        $seenSurveys = [];
        foreach ($input['warranties'] as $row) {
            if (!self::scope($row, $tenant)) {
                return $result;
            }
            if ($row['tenant_ref'] !== $tenant) {
                continue; // Do not disclose other tenants' records.
            }
            if (!self::keys($row, self::WARRANTY_KEYS)
                || !self::fixture($row['order_ref'], 'order')
                || !self::fixture($row['warranty_ref'], 'warranty')) {
                return $result;
            }
            $start = self::date($row['starts_on']);
            $end = self::date($row['ends_on']);
            if ($start === null || $end === null || $start > $end
                || isset($seenWarranties[$row['warranty_ref']])) {
                return $result;
            }
            $seenWarranties[$row['warranty_ref']] = true;
            $warranties[] = [
                'order_ref' => $row['order_ref'],
                'warranty_ref' => $row['warranty_ref'],
                'valid_from' => $row['starts_on'],
                'valid_until' => $row['ends_on'],
                'state' => $referenceDate < $row['starts_on'] ? 'NO_INICIADA'
                    : ($referenceDate > $row['ends_on'] ? 'VENCIDA' : 'VIGENTE'),
                'closed' => false,
                'approved' => false,
            ];
        }
        foreach ($input['communications'] as $row) {
            if (!self::scope($row, $tenant)) {
                return $result;
            }
            if ($row['tenant_ref'] !== $tenant) {
                continue;
            }
            if (!self::keys($row, self::COMMUNICATION_KEYS)
                || !self::fixture($row['order_ref'], 'order')
                || !self::fixture($row['template_ref'], 'template')) {
                return $result;
            }
            $communications[] = [
                'order_ref' => $row['order_ref'],
                'template_ref' => $row['template_ref'],
                'delivery_state' => 'NO_ENVIADO',
                'approved' => false,
            ];
        }
        foreach ($input['surveys'] as $row) {
            if (!self::scope($row, $tenant)) {
                return $result;
            }
            if ($row['tenant_ref'] !== $tenant) {
                continue;
            }
            if (!self::keys($row, self::SURVEY_KEYS)
                || !self::fixture($row['survey_ref'], 'survey')
                || !is_int($row['score']) || $row['score'] < 1 || $row['score'] > 5
                || !is_string($row['period'])
                || preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $row['period']) !== 1
                || self::date($row['period'] . '-01') === null
                || $row['period'] > substr($referenceDate, 0, 7)
                || isset($seenSurveys[$row['survey_ref']])) {
                return $result;
            }
            $seenSurveys[$row['survey_ref']] = true;
            if (!isset($totals[$row['period']])) {
                $totals[$row['period']] = ['sum' => 0, 'count' => 0];
            }
            $totals[$row['period']]['sum'] += $row['score'];
            $totals[$row['period']]['count']++;
        }
        ksort($totals, SORT_STRING);
        $periods = [];
        foreach ($totals as $period => $value) {
            $periods[] = [
                'period' => $period,
                'responses' => $value['count'],
                'average' => round($value['sum'] / $value['count'], 2),
                'state' => 'SINTETICO_NO_VALIDADO',
            ];
        }
        return [
            ...$result,
            'warranties' => $warranties,
            'communications' => $communications,
            'satisfaction' => $periods,
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyResult(?string $tenant): array
    {
        return [
            'tenant_ref' => $tenant,
            'state' => 'REQUIERE_REVISION',
            'warranties' => [],
            'communications' => [],
            'satisfaction' => [],
            'actions_taken' => [],
            'approved' => false,
            'closed' => false,
        ];
    }

    private static function tenant(mixed $tenant): bool
    {
        return is_string($tenant)
            && preg_match('/^tenant-[a-z0-9][a-z0-9_-]{0,39}$/D', $tenant) === 1;
    }

    private static function fixture(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /** @param mixed $row */
    private static function scope(mixed $row, string $tenant): bool
    {
        return is_array($row) && self::tenant($row['tenant_ref'] ?? null);
    }

    /**
     * @param array<array-key,mixed> $candidate
     * @param list<string> $expected
     */
    private static function keys(array $candidate, array $expected): bool
    {
        return count($candidate) === count($expected)
            && count(array_intersect(array_keys($candidate), $expected)) === count($expected);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value
            ? $parsed : null;
    }

    /** Reject all non-allowlisted input types and real-looking PII before filtering by tenant. */
    private static function syntheticTree(mixed $value, int $depth = 0): bool
    {
        if ($depth > 6) {
            return false;
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_RECORDS + 5) {
                return false;
            }
            foreach ($value as $key => $item) {
                if (!self::syntheticTree($key, $depth + 1)
                    || !self::syntheticTree($item, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($value)) {
            return strlen($value) <= 100
                && !preg_match('/@|\+\d{7,}|\b\d{7,15}\b|https?:\/\/|\b(?:cedula|cédula|nit|telefono|teléfono|email|correo|nombre|customer_name)\b/i', $value);
        }
        return is_int($value) || is_bool($value) || $value === null;
    }
}

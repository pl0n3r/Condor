<?php

declare(strict_types=1);

namespace App\Application\Finance;

/** M07 S03-S06: pure synthetic COP reconciliation and budgets. No I/O or approval. */
final class FinanceReconciliationBudget
{
    private const MAX_ROWS = 100;
    private const ROOT_KEYS = ['fixture_ref', 'tenant_ref', 'legal_entity_ref', 'as_of',
        'ledger_entries', 'bank_movements', 'budgets', 'periods'];
    private const ENTRY_KEYS = ['entry_ref', 'tenant_ref', 'legal_entity_ref',
        'period_ref', 'budget_ref', 'amount_decimal', 'kind', 'booked_on', 'state'];
    private const MOVEMENT_KEYS = ['movement_ref', 'tenant_ref', 'legal_entity_ref',
        'entry_ref', 'amount_decimal', 'kind', 'booked_on', 'state'];
    private const BUDGET_KEYS = ['budget_ref', 'tenant_ref', 'legal_entity_ref',
        'period_ref', 'kind', 'planned_decimal', 'state'];
    private const PERIOD_KEYS = ['period_ref', 'tenant_ref', 'legal_entity_ref',
        'starts_on', 'ends_on', 'state'];

    /**
     * @param array<array-key,mixed> $payload
     * @return array<string,mixed>
     */
    public static function project(array $payload, string $tenantRef, string $entityRef): array
    {
        if (!self::fixture($tenantRef, 'tenant') || !self::fixture($entityRef, 'legal-entity')
            || !self::keys($payload, self::ROOT_KEYS)
            || !self::fixture($payload['fixture_ref'], 'finance-reconciliation')
            || $payload['tenant_ref'] !== $tenantRef || $payload['legal_entity_ref'] !== $entityRef
            || self::date($payload['as_of']) === null
        ) {
            return self::rejected();
        }
        foreach (['ledger_entries', 'bank_movements', 'budgets', 'periods'] as $name) {
            if (!self::boundedList($payload[$name])) {
                return self::rejected();
            }
        }
        $seen = [];
        $periods = [];
        foreach ($payload['periods'] as $record) {
            if (!is_array($record)
                || !self::row($record, self::PERIOD_KEYS, 'period_ref', 'finance-period', $tenantRef, $entityRef)
                || self::date($record['starts_on']) === null || self::date($record['ends_on']) === null
                || $record['starts_on'] > $record['ends_on'] || $record['ends_on'] > $payload['as_of']
                || !in_array($record['state'], ['BORRADOR', 'REQUIERE_REVISION'], true)
                || isset($seen[$record['period_ref']])
            ) {
                return self::rejected();
            }
            $seen[$record['period_ref']] = true;
            $periods[$record['period_ref']] = $record;
        }
        $budgets = [];
        foreach ($payload['budgets'] as $record) {
            if (!is_array($record)
                || !self::row($record, self::BUDGET_KEYS, 'budget_ref', 'finance-budget', $tenantRef, $entityRef)
                || !isset($periods[$record['period_ref']])
                || !in_array($record['kind'], ['INGRESO', 'EGRESO'], true)
                || !in_array($record['state'], ['BORRADOR', 'REQUIERE_REVISION'], true)
                || isset($seen[$record['budget_ref']])
            ) {
                return self::rejected();
            }
            $amount = self::cents($record['planned_decimal']);
            if ($amount === null) {
                return self::rejected();
            }
            $seen[$record['budget_ref']] = true;
            $budgets[$record['budget_ref']] = [
                'period_ref' => $record['period_ref'], 'kind' => $record['kind'],
                'planned_cents' => $amount, 'actual_cents' => 0,
            ];
        }
        $entries = [];
        foreach ($payload['ledger_entries'] as $record) {
            if (!is_array($record)
                || !self::row($record, self::ENTRY_KEYS, 'entry_ref', 'finance-entry', $tenantRef, $entityRef)
                || !isset($periods[$record['period_ref']], $budgets[$record['budget_ref']])
                || $budgets[$record['budget_ref']]['period_ref'] !== $record['period_ref']
                || $budgets[$record['budget_ref']]['kind'] !== $record['kind']
                || !in_array($record['kind'], ['INGRESO', 'EGRESO'], true)
                || !in_array($record['state'], ['BORRADOR', 'REQUIERE_REVISION'], true)
                || self::date($record['booked_on']) === null
                || $record['booked_on'] < $periods[$record['period_ref']]['starts_on']
                || $record['booked_on'] > $periods[$record['period_ref']]['ends_on']
                || isset($seen[$record['entry_ref']])
            ) {
                return self::rejected();
            }
            $amount = self::cents($record['amount_decimal']);
            if ($amount === null) {
                return self::rejected();
            }
            $seen[$record['entry_ref']] = true;
            $entries[$record['entry_ref']] = ['amount_cents' => $amount, 'kind' => $record['kind']];
            $budgets[$record['budget_ref']]['actual_cents'] += $amount;
        }
        $used = [];
        $movements = [];
        $unmatchedCount = 0;
        $ordered = $payload['bank_movements'];
        // Malformed list members must fail closed before PHP's typed comparator runs.
        foreach ($ordered as $candidate) {
            if (!is_array($candidate)) {
                return self::rejected();
            }
        }
        usort($ordered, static fn (array $a, array $b): int =>
            strcmp((string) ($a['movement_ref'] ?? ''), (string) ($b['movement_ref'] ?? '')));
        foreach ($ordered as $record) {
            if (!is_array($record)
                || !self::row($record, self::MOVEMENT_KEYS, 'movement_ref', 'finance-movement', $tenantRef, $entityRef)
                || !self::fixture($record['entry_ref'], 'finance-entry')
                || !in_array($record['kind'], ['INGRESO', 'EGRESO'], true)
                || !in_array($record['state'], ['BORRADOR', 'REQUIERE_REVISION'], true)
                || self::date($record['booked_on']) === null
                || $record['booked_on'] > $payload['as_of']
                || isset($seen[$record['movement_ref']])
            ) {
                return self::rejected();
            }
            $amount = self::cents($record['amount_decimal']);
            if ($amount === null) {
                return self::rejected();
            }
            $seen[$record['movement_ref']] = true;
            $entry = $entries[$record['entry_ref']] ?? null;
            $matched = $entry !== null && $entry['kind'] === $record['kind']
                && $entry['amount_cents'] === $amount && !isset($used[$record['entry_ref']]);
            if ($matched) {
                $used[$record['entry_ref']] = true;
            } else {
                $unmatchedCount++;
            }
            $movements[] = [
                'movement_ref' => $record['movement_ref'],
                'entry_ref' => $record['entry_ref'],
                'candidate_match' => $matched, 'state' => 'REQUIERE_REVISION',
            ];
        }
        $outBudgets = [];
        foreach ($budgets as $ref => $budget) {
            $planned = $budget['planned_cents'];
            $actual = $budget['actual_cents'];
            $outBudgets[] = [
                'budget_ref' => $ref, 'period_ref' => $budget['period_ref'],
                'planned_decimal' => self::decimal($planned),
                'actual_decimal' => self::decimal($actual),
                'variance_decimal' => ($actual < $planned ? '-' : '') . self::decimal(abs($actual - $planned)),
                'state' => 'REQUIERE_REVISION',
            ];
        }
        usort($outBudgets, static fn (array $a, array $b): int => strcmp($a['budget_ref'], $b['budget_ref']));
        $pending = count($entries) !== count($used) || $unmatchedCount !== 0;
        $outPeriods = [];
        foreach ($periods as $ref => $period) {
            $outPeriods[] = [
                'period_ref' => $ref, 'review_candidate' => !$pending,
                'can_close' => false, 'state' => 'REQUIERE_REVISION',
            ];
        }
        usort($outPeriods, static fn (array $a, array $b): int => strcmp($a['period_ref'], $b['period_ref']));
        return [
            'state' => 'REQUIERE_REVISION', 'synthetic_only' => true,
            'can_close' => false, 'can_approve' => false, 'currency' => 'COP',
            'matched_candidate_count' => count($used), 'unmatched_movement_count' => $unmatchedCount,
            'bank_movements' => $movements, 'budgets' => $outBudgets, 'periods' => $outPeriods,
        ];
    }

    /** @return array<string,mixed> */
    private static function rejected(): array
    {
        return [
            'state' => 'REQUIERE_REVISION', 'synthetic_only' => true,
            'can_close' => false, 'can_approve' => false, 'currency' => 'COP',
            'matched_candidate_count' => 0, 'unmatched_movement_count' => 0,
            'bank_movements' => [], 'budgets' => [], 'periods' => [],
        ];
    }

    /**
     * @param array<array-key,mixed> $record
     * @param list<string> $expected
     */
    private static function keys(array $record, array $expected): bool
    {
        return count($record) === count($expected)
            && array_diff(array_keys($record), $expected) === [];
    }

    /**
     * @param array<array-key,mixed> $record
     * @param list<string> $expected
     */
    private static function row(array $record, array $expected, string $idKey, string $idType, string $tenant, string $entity): bool
    {
        return self::keys($record, $expected) && self::fixture($record[$idKey], $idType)
            && $record['tenant_ref'] === $tenant && $record['legal_entity_ref'] === $entity;
    }

    private static function fixture(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/(?=[a-z0-9_-]*[a-z])[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function boundedList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= self::MAX_ROWS;
    }

    private static function cents(mixed $value): ?int
    {
        if (!is_string($value)
            || preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{2}))?$/D', $value, $groups) !== 1) {
            return null;
        }
        return ((int) $groups[1] * 100) + (int) ($groups[2] ?? '0');
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $result = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $result instanceof \DateTimeImmutable && $result->format('Y-m-d') === $value ? $result : null;
    }
}

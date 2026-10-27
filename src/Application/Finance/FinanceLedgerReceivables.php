<?php

declare(strict_types=1);

namespace App\Application\Finance;

/**
 * M07 S01-S02: pure offline, disposable fictional COP balances.
 * Decimal inputs are converted to integer centavos, never floating point.
 * This projection is not bookkeeping, tax advice, a payment or an approval.
 */
final class FinanceLedgerReceivables
{
    private const LEDGER_KEYS = [
        'fixture_ref', 'tenant_ref', 'legal_entity_ref', 'as_of', 'entries', 'accounts',
    ];
    private const ENTRY_KEYS = [
        'entry_ref', 'tenant_ref', 'legal_entity_ref', 'kind', 'amount_decimal', 'booked_on', 'state',
    ];
    private const ACCOUNT_KEYS = [
        'account_ref', 'tenant_ref', 'legal_entity_ref', 'direction', 'amount_decimal',
        'booked_on', 'due_on', 'state',
    ];
    private const MAX_ROWS = 100;

    /**
     * @param array<array-key,mixed> $ledger Fictional fixtures, immediately discarded
     * @return array<string,mixed> Minimal review-only projection, never identity data
     */
    public static function project(array $ledger, string $tenantRef, string $legalEntityRef): array
    {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::fixture($legalEntityRef, 'legal-entity')
            || !self::exactKeys($ledger, self::LEDGER_KEYS)
            || !self::fixture($ledger['fixture_ref'], 'finance-ledger')
            || $ledger['tenant_ref'] !== $tenantRef
            || $ledger['legal_entity_ref'] !== $legalEntityRef
            || !self::date($ledger['as_of'])
            || !self::boundedList($ledger['entries'])
            || !self::boundedList($ledger['accounts'])
        ) {
            return self::rejected();
        }

        $entries = [];
        $accounts = [];
        $seen = [];
        $incomeCents = 0;
        $expenseCents = 0;
        $receivableCents = 0;
        $payableCents = 0;
        $reference = self::parsedDate($ledger['as_of']);
        if ($reference === null) {
            return self::rejected();
        }

        foreach ($ledger['entries'] as $entry) {
            if (!is_array($entry)
                || !self::exactKeys($entry, self::ENTRY_KEYS)
                || !self::fixture($entry['entry_ref'], 'finance-entry')
                || $entry['tenant_ref'] !== $tenantRef
                || $entry['legal_entity_ref'] !== $legalEntityRef
                || !in_array($entry['kind'], ['INGRESO', 'EGRESO'], true)
                || !in_array($entry['state'], ['BORRADOR', 'REQUIERE_REVISION'], true)
                || !self::date($entry['booked_on'])
                || $entry['booked_on'] > $ledger['as_of']
                || isset($seen[$entry['entry_ref']])
            ) {
                return self::rejected();
            }
            $amount = self::cents($entry['amount_decimal']);
            if ($amount === null) {
                return self::rejected();
            }
            $seen[$entry['entry_ref']] = true;
            if ($entry['kind'] === 'INGRESO') {
                $incomeCents += $amount;
            } else {
                $expenseCents += $amount;
            }
            $entries[] = [
                'entry_ref' => $entry['entry_ref'],
                'kind' => $entry['kind'],
                'amount_decimal' => self::decimal($amount),
                'booked_on' => $entry['booked_on'],
                'state' => 'REQUIERE_REVISION',
            ];
        }

        foreach ($ledger['accounts'] as $account) {
            if (!is_array($account)
                || !self::exactKeys($account, self::ACCOUNT_KEYS)
                || !self::fixture($account['account_ref'], 'finance-account')
                || $account['tenant_ref'] !== $tenantRef
                || $account['legal_entity_ref'] !== $legalEntityRef
                || !in_array($account['direction'], ['COBRAR', 'PAGAR'], true)
                || !in_array($account['state'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
                || !self::date($account['booked_on'])
                || !self::date($account['due_on'])
                || $account['booked_on'] > $ledger['as_of']
                || $account['due_on'] < $account['booked_on']
                || isset($seen[$account['account_ref']])
            ) {
                return self::rejected();
            }
            $amount = self::cents($account['amount_decimal']);
            $due = self::parsedDate($account['due_on']);
            if ($amount === null || $due === null) {
                return self::rejected();
            }
            $seen[$account['account_ref']] = true;
            if ($account['direction'] === 'COBRAR') {
                $receivableCents += $amount;
            } else {
                $payableCents += $amount;
            }
            $overdue = $due < $reference;
            $days = $overdue ? (int) $due->diff($reference)->days : 0;
            $band = !$overdue ? 'NO_VENCIDA' : ($days <= 30 ? 'VENCIDA_1_30'
                : ($days <= 60 ? 'VENCIDA_31_60'
                    : ($days <= 90 ? 'VENCIDA_61_90' : 'VENCIDA_MAS_90')));
            $accounts[] = [
                'account_ref' => $account['account_ref'],
                'direction' => $account['direction'],
                'amount_decimal' => self::decimal($amount),
                'due_on' => $account['due_on'],
                'overdue_days' => $days,
                'aging_band' => $band,
                'attention_candidate' => $overdue,
                'state' => 'REQUIERE_REVISION',
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['entry_ref'], $b['entry_ref']));
        usort($accounts, static fn (array $a, array $b): int => strcmp($a['account_ref'], $b['account_ref']));

        return [
            'state' => 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'currency' => 'COP',
            'income_total_decimal' => self::decimal($incomeCents),
            'expense_total_decimal' => self::decimal($expenseCents),
            'receivable_total_decimal' => self::decimal($receivableCents),
            'payable_total_decimal' => self::decimal($payableCents),
            'entries' => $entries,
            'accounts' => $accounts,
        ];
    }

    /** @return array<string,mixed> */
    private static function rejected(): array
    {
        return [
            'state' => 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'currency' => 'COP',
            'income_total_decimal' => '0.00',
            'expense_total_decimal' => '0.00',
            'receivable_total_decimal' => '0.00',
            'payable_total_decimal' => '0.00',
            'entries' => [],
            'accounts' => [],
        ];
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/(?=[a-z0-9_-]*[a-z])[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /**
     * @param array<array-key,mixed> $row
     * @param list<string> $required
     */
    private static function exactKeys(array $row, array $required): bool
    {
        return count($row) === count($required)
            && array_diff(array_keys($row), $required) === [];
    }

    private static function boundedList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= self::MAX_ROWS;
    }

    /** Exact fixed-scale COP decimals; 12 integer digits cap all 100-row sums on 64-bit PHP. */
    private static function cents(mixed $value): ?int
    {
        if (!is_string($value)
            || preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{2}))?$/D', $value, $matches) !== 1
        ) {
            return null;
        }
        return ((int) $matches[1] * 100) + (int) ($matches[2] ?? '0');
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function date(mixed $value): bool
    {
        return self::parsedDate($value) !== null;
    }

    private static function parsedDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $result = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $result instanceof \DateTimeImmutable && $result->format('Y-m-d') === $value
            ? $result : null;
    }
}

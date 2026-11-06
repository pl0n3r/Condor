<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use InvalidArgumentException;

/** M09/S08: deterministic, synthetic-only unpaid obligations; no database or payment side effects. */
final class PayablesPreview
{
    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public static function preview(array $payload): array
    {
        self::exactKeys($payload, [
            'tenant_ref', 'legal_entity_ref', 'site_ref', 'mode', 'obligations',
        ]);
        foreach ([
            'tenant_ref' => 'fixture:tenant/current',
            'legal_entity_ref' => 'fixture:legal-entity/current',
            'site_ref' => 'fixture:site/current',
        ] as $field => $expected) {
            if ($payload[$field] !== $expected) {
                throw new InvalidArgumentException('SCOPE_MISMATCH');
            }
        }
        if ($payload['mode'] !== 'preview_only') {
            throw new InvalidArgumentException('PREVIEW_ONLY');
        }
        $submitted = $payload['obligations'];
        if (!is_array($submitted) || !array_is_list($submitted)
            || count($submitted) < 1 || count($submitted) > 20) {
            throw new InvalidArgumentException('INVALID_OBLIGATIONS');
        }

        $obligations = [];
        $sum = 0;
        foreach ($submitted as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('INVALID_OBLIGATION');
            }
            self::exactKeys($row, [
                'obligation_ref', 'supplier_ref', 'tenant_ref', 'legal_entity_ref',
                'site_ref', 'amount_cop', 'currency', 'reconciliation_status',
                'payment_status',
            ]);
            $id = self::fixture($row['obligation_ref'], 'payable');
            self::fixture($row['supplier_ref'], 'supplier');
            foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $field) {
                if ($row[$field] !== $payload[$field]) {
                    throw new InvalidArgumentException('SCOPE_MISMATCH');
                }
            }
            $amount = $row['amount_cop'];
            if (!is_string($amount)
                || preg_match('/\A[1-9][0-9]{0,11}\z/D', $amount) !== 1) {
                throw new InvalidArgumentException('INVALID_COP_AMOUNT');
            }
            if ($row['currency'] !== 'COP'
                || $row['reconciliation_status'] !== 'unreconciled'
                || $row['payment_status'] !== 'unpaid') {
                throw new InvalidArgumentException('UNPAID_ONLY');
            }
            if (isset($obligations[$id])) {
                throw new InvalidArgumentException('DUPLICATE_OBLIGATION');
            }
            $sum += (int) $amount;
            $obligations[$id] = [
                'obligation_ref' => $id,
                'supplier_ref' => $row['supplier_ref'],
                'amount_cop' => $amount,
                'currency' => 'COP',
                'reconciliation_status' => 'unreconciled',
                'payment_status' => 'unpaid',
            ];
        }
        ksort($obligations, SORT_STRING);

        return [
            'tenant_ref' => $payload['tenant_ref'],
            'legal_entity_ref' => $payload['legal_entity_ref'],
            'site_ref' => $payload['site_ref'],
            'mode' => 'preview_only',
            'status' => 'UNPAID_UNRECONCILED',
            'obligations' => array_values($obligations),
            'obligation_count' => count($obligations),
            'total_amount_cop' => (string) $sum,
            'currency' => 'COP',
            'can_pay' => false,
            'can_reconcile' => false,
            'can_settle' => false,
            'payments_created' => 0,
            'accounting_posted' => false,
            'persisted' => false,
            'notified' => false,
        ];
    }

    /** @param array<string,mixed> $data
     *  @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
    }

    private static function fixture(mixed $reference, string $type): string
    {
        if (!is_string($reference)
            || preg_match('~\Afixture:'.preg_quote($type, '~').'/[a-z0-9][a-z0-9-]{0,63}\z~D', $reference) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
        return $reference;
    }
}

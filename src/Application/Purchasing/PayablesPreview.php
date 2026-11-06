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
        self::requireFields($payload, [
            'tenant_ref', 'legal_entity_ref', 'site_ref', 'mode', 'obligations',
        ]);
        if ($payload['tenant_ref'] !== 'fixture:tenant/current'
            || $payload['legal_entity_ref'] !== 'fixture:legal-entity/current'
            || $payload['site_ref'] !== 'fixture:site/current') {
            throw new InvalidArgumentException('SCOPE_MISMATCH');
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
        foreach ($submitted as $candidate) {
            $row = self::normalizeObligation($candidate, $payload);
            $key = $row['obligation_ref'];
            if (isset($obligations[$key])) {
                throw new InvalidArgumentException('DUPLICATE_OBLIGATION');
            }
            $obligations[$key] = $row;
            $sum += (int) $row['amount_cop'];
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

    /** @param array<string,mixed> $scope
     *  @return array<string,string>
     */
    private static function normalizeObligation(mixed $candidate, array $scope): array
    {
        if (!is_array($candidate) || array_is_list($candidate)) {
            throw new InvalidArgumentException('INVALID_OBLIGATION');
        }
        self::requireFields($candidate, [
            'obligation_ref', 'supplier_ref', 'tenant_ref', 'legal_entity_ref',
            'site_ref', 'amount_cop', 'currency', 'reconciliation_status',
            'payment_status',
        ]);
        $obligation = self::fixture($candidate['obligation_ref'], 'payable');
        $supplier = self::fixture($candidate['supplier_ref'], 'supplier');
        if ([
            $candidate['tenant_ref'], $candidate['legal_entity_ref'], $candidate['site_ref'],
        ] !== [
            $scope['tenant_ref'], $scope['legal_entity_ref'], $scope['site_ref'],
        ]) {
            throw new InvalidArgumentException('SCOPE_MISMATCH');
        }
        $amount = $candidate['amount_cop'];
        if (!is_string($amount) || preg_match('/\A[1-9][0-9]{0,11}\z/D', $amount) !== 1) {
            throw new InvalidArgumentException('INVALID_COP_AMOUNT');
        }
        if ($candidate['currency'] !== 'COP'
            || $candidate['reconciliation_status'] !== 'unreconciled'
            || $candidate['payment_status'] !== 'unpaid') {
            throw new InvalidArgumentException('UNPAID_ONLY');
        }
        return [
            'obligation_ref' => $obligation,
            'supplier_ref' => $supplier,
            'amount_cop' => $amount,
            'currency' => 'COP',
            'reconciliation_status' => 'unreconciled',
            'payment_status' => 'unpaid',
        ];
    }

    /** @param array<string,mixed> $input
     *  @param list<string> $required
     */
    private static function requireFields(array $input, array $required): void
    {
        $allowed = array_fill_keys($required, true);
        if (count($input) !== count($allowed)) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
        foreach (array_keys($input) as $field) {
            if (!isset($allowed[$field])) {
                throw new InvalidArgumentException('UNEXPECTED_FIELDS');
            }
        }
    }

    private static function fixture(mixed $reference, string $type): string
    {
        $prefix = sprintf('fixture:%s/', $type);
        if (!is_string($reference) || !str_starts_with($reference, $prefix)) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
        $slug = substr($reference, strlen($prefix));
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $slug) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
        return $reference;
    }
}

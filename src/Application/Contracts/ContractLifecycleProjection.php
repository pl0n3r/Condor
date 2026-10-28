<?php

declare(strict_types=1);

namespace App\Application\Contracts;

/**
 * M02 S04-S08: deterministic synthetic-only contract lifecycle preview.
 * No real signatures, labour advice, legal settlement, persistence or I/O.
 */
final class ContractLifecycleProjection
{
    private const LIMIT = 100;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function project(array $input): array
    {
        $invalid = [
            'valid' => false, 'state' => 'REQUIERE_REVISION',
            'tenant_ref' => null, 'company_ref' => null,
            'contracts' => [], 'signature_evidence' => [], 'renewals' => [],
            'terminations' => [], 'settlement_drafts' => [],
            'error' => 'invalid_synthetic_fixture',
        ];
        if (!self::keys($input, [
            'company_ref', 'contracts', 'reference_date', 'renewals',
            'settlement_drafts', 'signature_evidence', 'tenant_ref', 'terminations',
        ]) || !self::tenant($input['tenant_ref'])
            || !self::fixture($input['company_ref'], 'company')
            || !self::date($input['reference_date'])
            || !self::rows($input['contracts'])
            || !self::rows($input['signature_evidence'])
            || !self::rows($input['renewals'])
            || !self::rows($input['terminations'])
            || !self::rows($input['settlement_drafts'])
        ) {
            return $invalid;
        }
        $scope = [
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
        ];
        $alertLimit = (new \DateTimeImmutable($input['reference_date']))
            ->add(new \DateInterval('P30D'))->format('Y-m-d');
        $contracts = [];
        foreach ($input['contracts'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'end_on', 'ref', 'start_on', 'tenant_ref', 'worker_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'contract')
                || !self::fixture($row['worker_ref'], 'worker')
                || !self::date($row['start_on']) || !self::date($row['end_on'])
                || $row['start_on'] > $row['end_on']
                || isset($contracts[$row['ref']])
            ) {
                return $invalid;
            }
            $contracts[$row['ref']] = [
                'ref' => $row['ref'], 'worker_ref' => $row['worker_ref'],
                'start_on' => $row['start_on'], 'end_on' => $row['end_on'],
                'state' => 'REQUIERE_REVISION', 'signature_validated' => false,
                'closed' => false, 'renewal_attention_candidate' => $row['end_on'] <= $alertLimit,
            ];
        }
        $signatures = [];
        foreach ($input['signature_evidence'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'captured_on', 'company_ref', 'contract_ref', 'ref', 'reviewer_ref', 'tenant_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'signature-evidence')
                || !self::fixture($row['contract_ref'], 'contract')
                || !isset($contracts[$row['contract_ref']])
                || !self::fixture($row['reviewer_ref'], 'reviewer')
                || !self::date($row['captured_on'])
                || $row['captured_on'] < $contracts[$row['contract_ref']]['start_on']
                || isset($signatures[$row['ref']])
            ) {
                return $invalid;
            }
            $signatures[$row['ref']] = [
                'ref' => $row['ref'], 'contract_ref' => $row['contract_ref'],
                'captured_on' => $row['captured_on'], 'state' => 'REQUIERE_REVISION',
                'synthetic_evidence_only' => true, 'signature_validated' => false,
            ];
        }
        $renewals = [];
        foreach ($input['renewals'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'contract_ref', 'proposed_end_on', 'ref', 'reviewer_ref', 'tenant_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'renewal')
                || !self::fixture($row['contract_ref'], 'contract')
                || !isset($contracts[$row['contract_ref']])
                || !self::fixture($row['reviewer_ref'], 'reviewer')
                || !self::date($row['proposed_end_on'])
                || $row['proposed_end_on'] <= $contracts[$row['contract_ref']]['end_on']
                || isset($renewals[$row['ref']])
            ) {
                return $invalid;
            }
            $renewals[$row['ref']] = [
                'ref' => $row['ref'], 'contract_ref' => $row['contract_ref'],
                'proposed_end_on' => $row['proposed_end_on'],
                'state' => 'REQUIERE_REVISION', 'applied' => false,
                'notification_sent' => false,
            ];
        }
        $terminations = [];
        foreach ($input['terminations'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'contract_ref', 'effective_on', 'reason_key', 'ref',
                'reviewer_ref', 'tenant_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'termination')
                || !self::fixture($row['contract_ref'], 'contract')
                || !isset($contracts[$row['contract_ref']])
                || !self::fixture($row['reviewer_ref'], 'reviewer')
                || !self::date($row['effective_on'])
                || $row['effective_on'] < $contracts[$row['contract_ref']]['start_on']
                || !in_array($row['reason_key'], [
                    'TERM_REVIEW', 'MUTUAL_REVIEW', 'OTHER_REVIEW',
                ], true)
                || isset($terminations[$row['ref']])
            ) {
                return $invalid;
            }
            $terminations[$row['ref']] = [
                'ref' => $row['ref'], 'contract_ref' => $row['contract_ref'],
                'effective_on' => $row['effective_on'], 'reason_key' => $row['reason_key'],
                'state' => 'REQUIERE_REVISION', 'contract_closed' => false,
            ];
        }
        $settlements = [];
        foreach ($input['settlement_drafts'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'ref', 'reviewer_ref', 'tenant_ref', 'termination_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'settlement-draft')
                || !self::fixture($row['termination_ref'], 'termination')
                || !isset($terminations[$row['termination_ref']])
                || !self::fixture($row['reviewer_ref'], 'reviewer')
                || isset($settlements[$row['ref']])
            ) {
                return $invalid;
            }
            $settlements[$row['ref']] = [
                'ref' => $row['ref'], 'termination_ref' => $row['termination_ref'],
                'state' => 'REQUIERE_REVISION', 'legal_value' => false,
                'notice' => 'SIN_VALOR_LEGAL', 'amount_cop' => null,
                'approved' => false,
            ];
        }
        ksort($contracts);
        ksort($signatures);
        ksort($renewals);
        ksort($terminations);
        ksort($settlements);
        return [
            'valid' => true, 'state' => 'REQUIERE_REVISION', ...$scope,
            'contracts' => array_values($contracts),
            'signature_evidence' => array_values($signatures),
            'renewals' => array_values($renewals),
            'terminations' => array_values($terminations),
            'settlement_drafts' => array_values($settlements),
            'error' => null,
        ];
    }

    /** @param array<string,mixed> $row @param array{tenant_ref:string,company_ref:string} $scope */
    private static function scope(array $row, array $scope): bool
    {
        return $row['tenant_ref'] === $scope['tenant_ref']
            && $row['company_ref'] === $scope['company_ref'];
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
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function rows(mixed $rows): bool
    {
        return is_array($rows) && array_is_list($rows) && count($rows) <= self::LIMIT;
    }

    /** @param array<array-key,mixed> $row @param list<string> $expected */
    private static function keys(array $row, array $expected): bool
    {
        if (count($row) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $row)) {
                return false;
            }
        }
        return true;
    }
}

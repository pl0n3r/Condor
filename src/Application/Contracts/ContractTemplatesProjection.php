<?php

declare(strict_types=1);

namespace App\Application\Contracts;

/**
 * Pure, synthetic-only preview of templates, draft contracts, and addenda.
 * No legal status, real people, persistence, external I/O, or auto-approval.
 */
final class ContractTemplatesProjection
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
            'templates' => [], 'contracts' => [], 'addenda' => [],
            'error' => 'invalid_synthetic_fixture',
        ];
        if (!self::keys($input, ['addenda', 'company_ref', 'contracts', 'templates', 'tenant_ref'])
            || !self::tenant($input['tenant_ref'])
            || !self::fixture($input['company_ref'], 'company')
            || !self::rows($input['templates'])
            || !self::rows($input['contracts'])
            || !self::rows($input['addenda'])
        ) {
            return $invalid;
        }
        $scope = [
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
        ];
        $templates = [];
        foreach ($input['templates'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'ref', 'reviewer_ref', 'tenant_ref', 'version',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'template')
                || !self::fixture($row['reviewer_ref'], 'reviewer')
                || !is_int($row['version']) || $row['version'] < 1 || $row['version'] > 999
                || isset($templates[$row['ref']])
            ) {
                return $invalid;
            }
            $templates[$row['ref']] = [
                'ref' => $row['ref'], 'version' => $row['version'],
                'reviewer_ref' => $row['reviewer_ref'],
                'state' => 'REQUIERE_REVISION', 'review_required' => true,
            ];
        }
        $contracts = [];
        foreach ($input['contracts'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'ref', 'template_ref', 'template_version',
                'tenant_ref', 'worker_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'contract')
                || !self::fixture($row['worker_ref'], 'worker')
                || !self::fixture($row['template_ref'], 'template')
                || !isset($templates[$row['template_ref']])
                || $row['template_version'] !== $templates[$row['template_ref']]['version']
                || isset($contracts[$row['ref']])
            ) {
                return $invalid;
            }
            $contracts[$row['ref']] = [
                'ref' => $row['ref'], 'template_ref' => $row['template_ref'],
                'template_version' => $row['template_version'],
                'worker_ref' => $row['worker_ref'],
                'state' => 'REQUIERE_REVISION', 'signature_validated' => false,
            ];
        }
        $addenda = [];
        foreach ($input['addenda'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'company_ref', 'contract_ref', 'reason_key', 'ref', 'tenant_ref',
            ]) || !self::scope($row, $scope)
                || !self::fixture($row['ref'], 'addendum')
                || !self::fixture($row['contract_ref'], 'contract')
                || !isset($contracts[$row['contract_ref']])
                || !in_array($row['reason_key'], ['SCOPE_CHANGE', 'TERM_REVIEW', 'OTHER_REVIEW'], true)
                || isset($addenda[$row['ref']])
            ) {
                return $invalid;
            }
            $addenda[$row['ref']] = [
                'ref' => $row['ref'], 'contract_ref' => $row['contract_ref'],
                'reason_key' => $row['reason_key'],
                'state' => 'REQUIERE_REVISION', 'review_required' => true,
            ];
        }
        ksort($templates);
        ksort($contracts);
        ksort($addenda);
        return [
            'valid' => true, 'state' => 'REQUIERE_REVISION',
            ...$scope,
            'templates' => array_values($templates),
            'contracts' => array_values($contracts),
            'addenda' => array_values($addenda),
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{tenant_ref:string,company_ref:string} $scope
     */
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

    private static function rows(mixed $rows): bool
    {
        return is_array($rows) && array_is_list($rows) && count($rows) <= self::LIMIT;
    }

    /**
     * @param array<array-key,mixed> $row
     * @param list<string> $expected
     */
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

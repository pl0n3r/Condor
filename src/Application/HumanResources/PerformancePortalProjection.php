<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

/** Synthetic, offline, viewer-scoped performance portal. No approvals or I/O. */
final class PerformancePortalProjection
{
    private const MAX_ROWS = 100;

    /** @param array<string,mixed> $data
     *  @return array<string,mixed>
     */
    public static function project(array $data): array
    {
        $invalid = self::empty();
        if (!self::keys($data, ['tenant_ref', 'company_ref', 'viewer_employee_ref', 'as_of', 'evaluations'])
            || !self::synthetic($data['tenant_ref'], 'tenant') || !self::synthetic($data['company_ref'], 'company')
            || !self::synthetic($data['viewer_employee_ref'], 'employee')
            || !self::synthetic($data['as_of'], 'date') || !is_array($data['evaluations'])
            || !array_is_list($data['evaluations']) || count($data['evaluations']) > self::MAX_ROWS) {
            return $invalid;
        }
        $tenant = $data['tenant_ref'];
        $company = $data['company_ref'];
        $viewer = $data['viewer_employee_ref'];
        $seen = [];
        $visible = [];
        foreach ($data['evaluations'] as $row) {
            if (!is_array($row) || !self::keys($row, [
                'tenant_ref', 'company_ref', 'ref', 'cycle_ref', 'employee_ref',
                'evaluator_ref', 'kind', 'state', 'score',
            ]) || !self::synthetic($row['tenant_ref'], 'tenant') || !self::synthetic($row['company_ref'], 'company')
                || !self::synthetic($row['ref'], 'evaluation') || !self::synthetic($row['cycle_ref'], 'cycle')
                || !self::synthetic($row['employee_ref'], 'employee')
                || !self::synthetic($row['evaluator_ref'], 'employee')
                || !in_array($row['kind'], ['AUTOEVALUACION', 'JEFE'], true)
                || !in_array($row['state'], ['BORRADOR', 'EN_REVISION'], true)
                || ($row['score'] !== null && (!is_int($row['score']) || $row['score'] < 0 || $row['score'] > 100))) {
                return $invalid;
            }
            if ($row['tenant_ref'] !== $tenant || $row['company_ref'] !== $company
                || $row['employee_ref'] !== $viewer) {
                continue;
            }
            if (isset($seen[$row['ref']])) {
                return $invalid;
            }
            $seen[$row['ref']] = true;
            if ($row['kind'] === 'AUTOEVALUACION' && $row['evaluator_ref'] !== $viewer) {
                return $invalid;
            }
            $visible[] = [
                'ref' => $row['ref'], 'cycle_ref' => $row['cycle_ref'],
                'kind' => $row['kind'], 'score' => $row['score'],
                'state' => $row['state'], 'needs_human_review' => true,
                'automatically_approved' => false, 'automatically_closed' => false,
            ];
        }
        usort($visible, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        return [
            'valid' => true, 'tenant_ref' => $tenant, 'company_ref' => $company,
            'viewer_employee_ref' => $viewer, 'state' => 'REQUIERE_REVISION',
            'can_close' => false, 'can_approve' => false, 'evaluations' => $visible,
        ];
    }

    /** @return array<string,mixed> */
    private static function empty(): array
    {
        return ['valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'viewer_employee_ref' => null, 'state' => 'REQUIERE_REVISION',
            'can_close' => false, 'can_approve' => false, 'evaluations' => []];
    }

    /** Fail closed for non-synthetic identifiers and invalid dates. */
    private static function synthetic(mixed $value, string $kind): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if ($kind === 'tenant') {
            return preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
        }
        if ($kind === 'date') {
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $value) !== 1) {
                return false;
            }
            $pieces = array_map('intval', explode('-', $value));
            return checkdate($pieces[1], $pieces[2], $pieces[0]);
        }
        return preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /** @param array<string,mixed> $row
     *  @param list<string> $schema
     */
    private static function keys(array $row, array $schema): bool
    {
        return count($row) === count($schema)
            && array_diff($schema, array_keys($row)) === []
            && array_diff(array_keys($row), $schema) === [];
    }
}

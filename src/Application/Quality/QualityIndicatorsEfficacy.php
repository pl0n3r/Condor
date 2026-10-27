<?php

declare(strict_types=1);

namespace App\Application\Quality;

/**
 * M13 S01/S04/S07. Deterministic, tenant-scoped offline quality projection.
 * Inputs are synthetic fixtures only. No persistence, network, approvals or closure.
 */
final class QualityIndicatorsEfficacy
{
    private const MAX_ITEMS = 50;
    private const ROOT_KEYS = ['tenant_ref', 'indicators', 'audits', 'actions', 'verifications'];
    private const INDICATOR_KEYS = ['tenant_ref', 'indicator_ref', 'goal', 'previous', 'current', 'state'];
    private const AUDIT_KEYS = ['tenant_ref', 'audit_ref', 'auditor_ref', 'scheduled_on', 'state'];
    private const ACTION_KEYS = ['tenant_ref', 'action_ref', 'source_closed', 'closure_evidence_ref'];
    private const VERIFICATION_KEYS = [
        'tenant_ref', 'verification_ref', 'action_ref', 'evidence_ref', 'reviewer_ref', 'state',
    ];
    private const STATES = ['PENDIENTE', 'REQUIERE_REVISION'];

    /**
     * @param array<array-key, mixed> $input Only synthetic M13 indicators/audits/closed-source actions
     * @return array<string, mixed> Fail-closed projection without any certification
     */
    public static function project(array $input, string $tenantRef, string $referenceDate): array
    {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::date($referenceDate)
            || !self::exactKeys($input, self::ROOT_KEYS)
            || !self::fixture($input['tenant_ref'], 'tenant')
            || $input['tenant_ref'] !== $tenantRef
        ) {
            return self::result(false, [], [], []);
        }

        foreach (['indicators', 'audits', 'actions', 'verifications'] as $key) {
            if (!is_array($input[$key]) || !array_is_list($input[$key])
                || count($input[$key]) > self::MAX_ITEMS
            ) {
                return self::result(false, [], [], []);
            }
        }

        $indicators = [];
        $indicatorRefs = [];
        foreach ($input['indicators'] as $indicator) {
            if (!is_array($indicator)
                || !self::exactKeys($indicator, self::INDICATOR_KEYS)
                || !self::belongs($indicator, $tenantRef)
                || !self::fixture($indicator['indicator_ref'], 'quality-indicator')
                || !self::pending($indicator['state'])
                || !self::metric($indicator['goal'], true)
                || !self::metric($indicator['current'])
                || !self::metric($indicator['previous'])
                || isset($indicatorRefs[$indicator['indicator_ref']])
            ) {
                return self::result(false, [], [], []);
            }
            $indicatorRefs[$indicator['indicator_ref']] = true;
            $trend = $indicator['current'] > $indicator['previous'] ? 'SUBE'
                : ($indicator['current'] < $indicator['previous'] ? 'BAJA' : 'ESTABLE');
            $indicators[] = [
                'indicator_ref' => $indicator['indicator_ref'],
                'goal' => $indicator['goal'],
                'previous' => $indicator['previous'],
                'current' => $indicator['current'],
                'trend' => $trend,
                'target_observation' => $indicator['current'] >= $indicator['goal']
                    ? 'EN_META_NUMERICA' : 'BAJO_META_NUMERICA',
                'state' => $indicator['state'],
                'requires_professional_review' => true,
            ];
        }

        $audits = [];
        $auditRefs = [];
        foreach ($input['audits'] as $audit) {
            if (!is_array($audit)
                || !self::exactKeys($audit, self::AUDIT_KEYS)
                || !self::belongs($audit, $tenantRef)
                || !self::fixture($audit['audit_ref'], 'quality-audit')
                || !self::fixture($audit['auditor_ref'], 'quality-auditor')
                || !self::date($audit['scheduled_on'])
                || !self::pending($audit['state'])
                || isset($auditRefs[$audit['audit_ref']])
            ) {
                return self::result(false, [], [], []);
            }
            $auditRefs[$audit['audit_ref']] = true;
            $audits[] = [
                'audit_ref' => $audit['audit_ref'],
                'auditor_ref' => $audit['auditor_ref'],
                'scheduled_on' => $audit['scheduled_on'],
                'attention_overdue' => $audit['scheduled_on'] < $referenceDate,
                'state' => $audit['state'],
                'requires_professional_review' => true,
            ];
        }

        // An action's source-closed claim must be explicit and carry synthetic provenance.
        // This records prior external context; it does NOT close the action in Condor.
        $actionRefs = [];
        foreach ($input['actions'] as $action) {
            if (!is_array($action)
                || !self::exactKeys($action, self::ACTION_KEYS)
                || !self::belongs($action, $tenantRef)
                || !self::fixture($action['action_ref'], 'quality-action')
                || $action['source_closed'] !== true
                || !self::fixture($action['closure_evidence_ref'], 'quality-evidence')
                || isset($actionRefs[$action['action_ref']])
            ) {
                return self::result(false, [], [], []);
            }
            $actionRefs[$action['action_ref']] = true;
        }

        $verifications = [];
        $verificationRefs = [];
        foreach ($input['verifications'] as $check) {
            if (!is_array($check)
                || !self::exactKeys($check, self::VERIFICATION_KEYS)
                || !self::belongs($check, $tenantRef)
                || !self::fixture($check['verification_ref'], 'quality-verification')
                || !self::fixture($check['action_ref'], 'quality-action')
                || !isset($actionRefs[$check['action_ref']])
                || !self::fixture($check['evidence_ref'], 'quality-evidence')
                || !self::fixture($check['reviewer_ref'], 'quality-reviewer')
                || !self::pending($check['state'])
                || isset($verificationRefs[$check['verification_ref']])
            ) {
                return self::result(false, [], [], []);
            }
            $verificationRefs[$check['verification_ref']] = true;
            $verifications[] = [
                'verification_ref' => $check['verification_ref'],
                'action_ref' => $check['action_ref'],
                'evidence_ref' => $check['evidence_ref'],
                'reviewer_ref' => $check['reviewer_ref'],
                'state' => $check['state'],
                'efficacy_state' => 'REQUIERE_REVISION',
                'requires_professional_review' => true,
            ];
        }

        $requiresReview = false;
        foreach ([$indicators, $audits, $verifications] as $group) {
            foreach ($group as $row) {
                $requiresReview = $requiresReview || $row['state'] === 'REQUIERE_REVISION';
            }
        }

        return self::result(true, $indicators, $audits, $verifications, $requiresReview);
    }

    /**
     * @param list<array<string, mixed>> $indicators
     * @param list<array<string, mixed>> $audits
     * @param list<array<string, mixed>> $verifications
     * @return array<string, mixed>
     */
    private static function result(
        bool $valid, array $indicators, array $audits, array $verifications,
        bool $requiresReview = false
    ): array {
        return [
            'state' => $valid && !$requiresReview ? 'PENDIENTE' : 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'structurally_reviewable' => $valid,
            'requires_professional_review' => true,
            'indicator_count' => count($indicators),
            'audit_count' => count($audits),
            'verification_count' => count($verifications),
            'indicators' => $indicators,
            'audits' => $audits,
            'verifications' => $verifications,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function belongs(array $row, string $tenantRef): bool
    {
        return self::fixture($row['tenant_ref'], 'tenant')
            && $row['tenant_ref'] === $tenantRef;
    }

    private static function pending(mixed $state): bool
    {
        return is_string($state) && in_array($state, self::STATES, true);
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1
            && preg_match('~[0-9]{8,}~', $value) !== 1;
    }

    private static function metric(mixed $value, bool $positive = false): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= ($positive ? 0.000001 : 0)
            && $value <= 1000000000;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('~^[0-9]{4}-[0-9]{2}-[0-9]{2}$~D', $value) !== 1
        ) {
            return false;
        }
        $year = (int) substr($value, 0, 4);
        return $year >= 2000 && $year <= 2100
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), $year);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<string> $keys
     */
    private static function exactKeys(array $row, array $keys): bool
    {
        return count($row) === count($keys)
            && array_diff(array_keys($row), $keys) === [];
    }
}

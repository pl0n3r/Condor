<?php

declare(strict_types=1);

namespace App\Application\Risk;

/**
 * M12 S06-S08: deterministic, strictly synthetic and tenant-scoped projection.
 * No database, network, personal data, approvals or lifecycle transitions.
 */
final class RiskIncidentTreatment
{
    private const MAX_ITEMS = 50;
    private const ROOT_KEYS = ['incidents', 'plans', 'tenant_ref'];
    private const INCIDENT_KEYS = ['incident_ref', 'risk_ref', 'state', 'tenant_ref'];
    private const PLAN_KEYS = ['due_on', 'incident_ref', 'owner_ref', 'plan_ref', 'state', 'tenant_ref'];
    private const STATES = ['PENDIENTE', 'REQUIERE_REVISION'];

    /**
     * @param array<array-key,mixed> $input Only synthetic incidents and treatment plans
     * @return array<string,mixed> Fail-closed projection, never certification
     */
    public static function project(array $input, string $tenantRef, string $referenceDate): array
    {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::date($referenceDate)
            || !self::exactKeys($input, self::ROOT_KEYS)
            || !self::fixture($input['tenant_ref'], 'tenant')
            || $input['tenant_ref'] !== $tenantRef
            || !is_array($input['incidents']) || !array_is_list($input['incidents'])
            || !is_array($input['plans']) || !array_is_list($input['plans'])
            || count($input['incidents']) > self::MAX_ITEMS
            || count($input['plans']) > self::MAX_ITEMS
        ) {
            return self::result(false, [], []);
        }

        $incidentRefs = [];
        $incidents = [];
        foreach ($input['incidents'] as $incident) {
            if (!is_array($incident)
                || !self::exactKeys($incident, self::INCIDENT_KEYS)
                || !self::fixture($incident['tenant_ref'], 'tenant')
                || $incident['tenant_ref'] !== $tenantRef
                || !self::fixture($incident['incident_ref'], 'risk-incident')
                || !self::fixture($incident['risk_ref'], 'risk')
                || !self::pending($incident['state'])
                || isset($incidentRefs[$incident['incident_ref']])
            ) {
                return self::result(false, [], []);
            }
            $incidentRefs[$incident['incident_ref']] = true;
            $incidents[] = [
                'incident_ref' => $incident['incident_ref'],
                'risk_ref' => $incident['risk_ref'],
                'state' => $incident['state'],
            ];
        }

        $planRefs = [];
        $plans = [];
        foreach ($input['plans'] as $plan) {
            if (!is_array($plan)
                || !self::exactKeys($plan, self::PLAN_KEYS)
                || !self::fixture($plan['tenant_ref'], 'tenant')
                || $plan['tenant_ref'] !== $tenantRef
                || !self::fixture($plan['plan_ref'], 'risk-plan')
                || !self::fixture($plan['incident_ref'], 'risk-incident')
                || !isset($incidentRefs[$plan['incident_ref']])
                || !self::fixture($plan['owner_ref'], 'responsible')
                || !self::date($plan['due_on'])
                || !self::pending($plan['state'])
                || isset($planRefs[$plan['plan_ref']])
            ) {
                return self::result(false, [], []);
            }
            $planRefs[$plan['plan_ref']] = true;
            $plans[] = [
                'plan_ref' => $plan['plan_ref'],
                'incident_ref' => $plan['incident_ref'],
                'owner_ref' => $plan['owner_ref'],
                'due_on' => $plan['due_on'],
                'follow_up_overdue' => $plan['due_on'] < $referenceDate,
                'state' => $plan['state'],
            ];
        }

        $requiresReview = false;
        foreach ($incidents as $incident) {
            $requiresReview = $requiresReview || $incident['state'] === 'REQUIERE_REVISION';
        }
        foreach ($plans as $plan) {
            $requiresReview = $requiresReview || $plan['state'] === 'REQUIERE_REVISION';
        }

        return self::result(true, $incidents, $plans, $requiresReview);
    }

    /**
     * @param list<array<string,mixed>> $incidents
     * @param list<array<string,mixed>> $plans
     * @return array<string,mixed>
     */
    private static function result(
        bool $valid, array $incidents, array $plans, bool $requiresReview = false
    ): array
    {
        return [
            'state' => $valid && !$requiresReview ? 'PENDIENTE' : 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'structurally_reviewable' => $valid,
            'incident_count' => count($incidents),
            'plan_count' => count($plans),
            'incidents' => $incidents,
            'plans' => $plans,
        ];
    }

    private static function pending(mixed $state): bool
    {
        return is_string($state) && in_array($state, self::STATES, true);
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
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
     * @param array<array-key,mixed> $payload
     * @param list<string> $expected
     */
    private static function exactKeys(array $payload, array $expected): bool
    {
        return count($payload) === count($expected)
            && array_diff(array_keys($payload), $expected) === [];
    }
}

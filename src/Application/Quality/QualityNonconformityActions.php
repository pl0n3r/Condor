<?php

declare(strict_types=1);

namespace App\Application\Quality;

/**
 * M13 S02-S06: pure offline projection of synthetic nonconformities, causes
 * and corrective/preventive actions. Never verifies, approves, or closes work.
 */
final class QualityNonconformityActions
{
    private const MAX_ITEMS = 50;
    private const ROOT_KEYS = ['actions', 'nonconformities', 'tenant_ref'];
    private const NC_KEYS = [
        'cause_ref', 'cause_state', 'finding_ref', 'nonconformity_ref', 'state', 'tenant_ref',
    ];
    private const ACTION_KEYS = [
        'action_ref', 'due_on', 'kind', 'nonconformity_ref', 'owner_ref',
        'state', 'tenant_ref', 'verification_state',
    ];
    private const REVIEW_STATES = ['PENDIENTE', 'REQUIERE_REVISION'];
    private const ACTION_KINDS = ['CORRECTIVA', 'PREVENTIVA'];

    /**
     * @param array<array-key,mixed> $input Synthetic references only; no free-form person/company data
     * @return array<string,mixed> Deterministic, tenant-scoped and fail-closed projection
     */
    public static function project(array $input, string $tenantRef, string $referenceDate): array
    {
        if (!self::validEnvelope($input, $tenantRef, $referenceDate)) {
            return self::result(false, [], []);
        }

        $ncRefs = [];
        $nonconformities = [];
        $requiresReview = false;
        foreach ($input['nonconformities'] as $nc) {
            if (!is_array($nc) || !self::validNonconformity($nc, $tenantRef)
                || isset($ncRefs[$nc['nonconformity_ref']])
            ) {
                return self::result(false, [], []);
            }
            $ncRefs[$nc['nonconformity_ref']] = true;
            $requiresReview = $requiresReview
                || $nc['cause_state'] === 'REQUIERE_REVISION'
                || $nc['state'] === 'REQUIERE_REVISION';
            $nonconformities[] = [
                'nonconformity_ref' => $nc['nonconformity_ref'],
                'finding_ref' => $nc['finding_ref'],
                'cause_ref' => $nc['cause_ref'],
                'cause_state' => $nc['cause_state'],
                'state' => $nc['state'],
            ];
        }

        $actionRefs = [];
        $actions = [];
        foreach ($input['actions'] as $action) {
            if (!is_array($action) || !self::validAction($action, $tenantRef, $ncRefs)
                || isset($actionRefs[$action['action_ref']])
            ) {
                return self::result(false, [], []);
            }
            $actionRefs[$action['action_ref']] = true;
            $requiresReview = $requiresReview
                || $action['state'] === 'REQUIERE_REVISION'
                || $action['verification_state'] === 'REQUIERE_REVISION';
            $actions[] = [
                'action_ref' => $action['action_ref'],
                'nonconformity_ref' => $action['nonconformity_ref'],
                'kind' => $action['kind'],
                'owner_ref' => $action['owner_ref'],
                'due_on' => $action['due_on'],
                'follow_up_overdue' => $action['due_on'] < $referenceDate,
                'state' => $action['state'],
                'verification_state' => $action['verification_state'],
                'verified' => false,
            ];
        }

        return self::result(true, $nonconformities, $actions, $requiresReview);
    }

    /**
     * @param list<array<string,mixed>> $nonconformities
     * @param list<array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private static function result(
        bool $valid, array $nonconformities, array $actions, bool $requiresReview = false
    ): array {
        return [
            'state' => $valid && !$requiresReview ? 'PENDIENTE' : 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'structurally_reviewable' => $valid,
            'nonconformity_count' => count($nonconformities),
            'action_count' => count($actions),
            'nonconformities' => $nonconformities,
            'actions' => $actions,
        ];
    }

    /** @param array<array-key,mixed> $input */
    private static function validEnvelope(array $input, string $tenantRef, string $referenceDate): bool
    {
        return self::fixture($tenantRef, 'tenant')
            && self::date($referenceDate)
            && self::exactKeys($input, self::ROOT_KEYS)
            && $input['tenant_ref'] === $tenantRef
            && is_array($input['nonconformities'])
            && array_is_list($input['nonconformities'])
            && is_array($input['actions'])
            && array_is_list($input['actions'])
            && count($input['nonconformities']) <= self::MAX_ITEMS
            && count($input['actions']) <= self::MAX_ITEMS;
    }

    /** @param array<array-key,mixed> $nc */
    private static function validNonconformity(array $nc, string $tenantRef): bool
    {
        return self::exactKeys($nc, self::NC_KEYS)
            && $nc['tenant_ref'] === $tenantRef
            && self::fixture($nc['nonconformity_ref'], 'quality-nc')
            && self::fixture($nc['finding_ref'], 'quality-finding')
            && self::fixture($nc['cause_ref'], 'quality-cause')
            && self::pending($nc['cause_state'])
            && self::pending($nc['state']);
    }

    /**
     * @param array<array-key,mixed> $action
     * @param array<string,bool> $knownNonconformities
     */
    private static function validAction(
        array $action, string $tenantRef, array $knownNonconformities
    ): bool {
        if (!self::exactKeys($action, self::ACTION_KEYS) || $action['tenant_ref'] !== $tenantRef) {
            return false;
        }
        return self::fixture($action['action_ref'], 'quality-action')
            && self::fixture($action['nonconformity_ref'], 'quality-nc')
            && isset($knownNonconformities[$action['nonconformity_ref']])
            && self::fixture($action['owner_ref'], 'responsible')
            && self::date($action['due_on'])
            && is_string($action['kind'])
            && in_array($action['kind'], self::ACTION_KINDS, true)
            && self::pending($action['state'])
            && self::pending($action['verification_state']);
    }

    private static function pending(mixed $state): bool
    {
        return is_string($state) && in_array($state, self::REVIEW_STATES, true);
    }

    private static function fixture(mixed $value, string $type): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $prefix = 'fixture:' . $type . '/';
        if (!str_starts_with($value, $prefix)) {
            return false;
        }
        $token = substr($value, strlen($prefix));
        return preg_match('~\A[a-z0-9][a-z0-9_-]{0,47}\z~D', $token) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('~\A(?<year>[0-9]{4})-(?<month>[0-9]{2})-(?<day>[0-9]{2})\z~D', $value, $parts) !== 1
        ) {
            return false;
        }
        $year = (int) $parts['year'];
        return $year >= 2000 && $year <= 2100
            && checkdate((int) $parts['month'], (int) $parts['day'], $year);
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string> $expected
     */
    private static function exactKeys(array $payload, array $expected): bool
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }
}

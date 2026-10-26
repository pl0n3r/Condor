<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * F3c: pure, fictional SG-SST follow-up; never a legal or personnel decision.
 * F3b is an aggregate signal, not evidence for an individual action.
 */
final class OccupationalSafetyFollowUpProjection
{
    private const FOLLOWUP_KEYS = [
        'actions', 'checklist_ref', 'fixture_ref', 'legal_entity_ref', 'tenant_ref',
    ];
    private const ACTION_KEYS = [
        'action_ref', 'checklist_ref', 'legal_entity_ref', 'status', 'tenant_ref',
    ];
    private const CHECKLIST_KEYS = [
        'evidence_count', 'requires_professional_review', 'state',
        'structurally_reviewable', 'synthetic_only',
    ];
    private const MAX_ACTIONS = 50;

    /**
     * @param array<array-key,mixed> $followUp Synthetic action fixtures
     * @param array<array-key,mixed> $checklistSummary F3b aggregate, never proof of closure
     * @return array{state:string,requires_professional_review:true,synthetic_only:true,structurally_reviewable:bool,action_count:int,actions:list<array{action_ref:string,state:string}>}
     */
    public static function project(
        array $followUp,
        array $checklistSummary,
        string $tenantRef,
        string $legalEntityRef
    ): array {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::fixture($legalEntityRef, 'legal-entity')
            || !self::exactKeys($followUp, self::FOLLOWUP_KEYS)
            || !self::fixture($followUp['fixture_ref'], 'sgsst-followup')
            || !self::fixture($followUp['tenant_ref'], 'tenant')
            || !self::fixture($followUp['legal_entity_ref'], 'legal-entity')
            || !self::fixture($followUp['checklist_ref'], 'sgsst-checklist')
            || $followUp['tenant_ref'] !== $tenantRef
            || $followUp['legal_entity_ref'] !== $legalEntityRef
            || !self::exactKeys($checklistSummary, self::CHECKLIST_KEYS)
            || !is_string($checklistSummary['state'])
            || !in_array($checklistSummary['state'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
            || $checklistSummary['requires_professional_review'] !== true
            || $checklistSummary['synthetic_only'] !== true
            || !is_bool($checklistSummary['structurally_reviewable'])
            || !is_int($checklistSummary['evidence_count'])
            || $checklistSummary['evidence_count'] < 0
            || $checklistSummary['evidence_count'] > self::MAX_ACTIONS
            || $checklistSummary['structurally_reviewable']
                !== ($checklistSummary['evidence_count'] > 0)
            || ($checklistSummary['state'] === 'REQUIERE_REVISION'
                && $checklistSummary['structurally_reviewable'] !== true)
            || !is_array($followUp['actions'])
            || !array_is_list($followUp['actions'])
            || count($followUp['actions']) > self::MAX_ACTIONS
        ) {
            return self::result('REQUIERE_REVISION', false, []);
        }

        $seen = [];
        $projected = [];
        $requiresReview = $checklistSummary['state'] === 'REQUIERE_REVISION';
        foreach ($followUp['actions'] as $action) {
            if (!is_array($action)
                || !self::exactKeys($action, self::ACTION_KEYS)
                || !self::fixture($action['action_ref'], 'sgsst-action')
                || !self::fixture($action['tenant_ref'], 'tenant')
                || !self::fixture($action['legal_entity_ref'], 'legal-entity')
                || !self::fixture($action['checklist_ref'], 'sgsst-checklist')
                || $action['tenant_ref'] !== $tenantRef
                || $action['legal_entity_ref'] !== $legalEntityRef
                || $action['checklist_ref'] !== $followUp['checklist_ref']
                || !is_string($action['status'])
                || !in_array($action['status'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
                || isset($seen[$action['action_ref']])
            ) {
                return self::result('REQUIERE_REVISION', false, []);
            }
            $seen[$action['action_ref']] = true;
            $requiresReview = $requiresReview || $action['status'] === 'REQUIERE_REVISION';
            $projected[] = [
                'action_ref' => $action['action_ref'],
                // F3b cannot attribute evidence to an individual action.
                'state' => $checklistSummary['state'] === 'REQUIERE_REVISION'
                    ? 'REQUIERE_REVISION' : $action['status'],
            ];
        }

        return self::result(
            $requiresReview ? 'REQUIERE_REVISION' : 'PENDIENTE',
            count($projected) > 0 && $checklistSummary['structurally_reviewable'],
            $projected
        );
    }

    /**
     * @param list<array{action_ref:string,state:string}> $actions
     * @return array{state:string,requires_professional_review:true,synthetic_only:true,structurally_reviewable:bool,action_count:int,actions:list<array{action_ref:string,state:string}>}
     */
    private static function result(string $state, bool $reviewable, array $actions): array
    {
        return [
            'state' => $state,
            'requires_professional_review' => true,
            'synthetic_only' => true,
            'structurally_reviewable' => $reviewable,
            'action_count' => count($actions),
            'actions' => $actions,
        ];
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
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

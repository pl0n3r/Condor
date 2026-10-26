<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * F3b: pure, synthetic-only SG-SST evidence metadata checklist.
 * No legal obligations, personnel records, documents or compliance decisions.
 */
final class OccupationalSafetyEvidenceChecklistProjection
{
    private const CHECKLIST_KEYS = [
        'evidence', 'fixture_ref', 'legal_entity_ref', 'precheck_ref', 'tenant_ref',
    ];
    private const EVIDENCE_KEYS = ['evidence_ref', 'status'];
    private const PRECHECK_KEYS = [
        'requires_professional_review', 'state', 'structurally_reviewable', 'synthetic_only',
    ];
    private const MAX_EVIDENCE = 50;

    /**
     * @param array<array-key,mixed> $checklist Fictional references only
     * @param array<array-key,mixed> $precheck F3a pure projection (not an attestation)
     * @return array{state:string,requires_professional_review:true,synthetic_only:true,structurally_reviewable:bool,evidence_count:int}
     */
    public static function project(
        array $checklist,
        array $precheck,
        string $tenantRef,
        string $legalEntityRef
    ): array {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::fixture($legalEntityRef, 'legal-entity')
            || !self::exactKeys($checklist, self::CHECKLIST_KEYS)
            || !self::fixture($checklist['fixture_ref'], 'sgsst-checklist')
            || !self::fixture($checklist['tenant_ref'], 'tenant')
            || !self::fixture($checklist['legal_entity_ref'], 'legal-entity')
            || !self::fixture($checklist['precheck_ref'], 'sgsst-profile')
            || $checklist['tenant_ref'] !== $tenantRef
            || $checklist['legal_entity_ref'] !== $legalEntityRef
            || !self::exactKeys($precheck, self::PRECHECK_KEYS)
            || $precheck['state'] !== 'EN_REVISION'
            || $precheck['requires_professional_review'] !== true
            || $precheck['synthetic_only'] !== true
            || $precheck['structurally_reviewable'] !== true
            || !is_array($checklist['evidence'])
            || !array_is_list($checklist['evidence'])
            || count($checklist['evidence']) > self::MAX_EVIDENCE
        ) {
            return self::result('REQUIERE_REVISION', false, 0);
        }

        $seen = [];
        $requiresReview = false;
        foreach ($checklist['evidence'] as $item) {
            if (!is_array($item)
                || !self::exactKeys($item, self::EVIDENCE_KEYS)
                || !self::fixture($item['evidence_ref'], 'sgsst-evidence')
                || !is_string($item['status'])
                || !in_array($item['status'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
                || isset($seen[$item['evidence_ref']])
            ) {
                return self::result('REQUIERE_REVISION', false, 0);
            }
            $seen[$item['evidence_ref']] = true;
            $requiresReview = $requiresReview || $item['status'] === 'REQUIERE_REVISION';
        }

        // An empty checklist and all-pending fixtures are NOT evidence of compliance.
        return self::result(
            $requiresReview ? 'REQUIERE_REVISION' : 'PENDIENTE',
            count($checklist['evidence']) > 0,
            count($checklist['evidence'])
        );
    }

    /** @return array{state:string,requires_professional_review:true,synthetic_only:true,structurally_reviewable:bool,evidence_count:int} */
    private static function result(string $state, bool $reviewable, int $count): array
    {
        return [
            'state' => $state,
            'requires_professional_review' => true,
            'synthetic_only' => true,
            'structurally_reviewable' => $reviewable,
            'evidence_count' => $count,
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

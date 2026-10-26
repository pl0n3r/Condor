<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * F3a — offline, fictional-only SG-SST intake precheck, NOT a legal assessment.
 *
 * Structural validity merely means a professional can review the fixture.
 * No input ever constitutes compliance, non-applicability or legal advice.
 */
final class OccupationalSafetyPrecheckProjection
{
    private const PROFILE_KEYS = [
        'fixture_ref', 'legal_entity_ref', 'reference_date', 'risk_category',
        'tenant_ref', 'workers_max', 'workers_min',
    ];
    private const RISK_CATEGORIES = ['I', 'II', 'III', 'IV', 'V'];
    private const MAX_WORKERS = 1000000;

    /**
     * @param array<array-key,mixed> $profile Fictional fixture only; no employees or PII
     * @return array{state:string,requires_professional_review:true,synthetic_only:true,structurally_reviewable:bool}
     */
    public static function project(
        array $profile,
        string $tenantRef,
        string $legalEntityRef,
        string $referenceDate
    ): array {
        $result = self::review(false);
        if (!self::fixture($tenantRef, 'tenant')
            || !self::fixture($legalEntityRef, 'legal-entity')
            || !self::exactKeys($profile, self::PROFILE_KEYS)
            || !self::fixture($profile['fixture_ref'], 'sgsst-profile')
            || !self::fixture($profile['tenant_ref'], 'tenant')
            || !self::fixture($profile['legal_entity_ref'], 'legal-entity')
            || $profile['tenant_ref'] !== $tenantRef
            || $profile['legal_entity_ref'] !== $legalEntityRef
            || !is_int($profile['workers_min'])
            || !is_int($profile['workers_max'])
            || $profile['workers_min'] < 0
            || $profile['workers_max'] < $profile['workers_min']
            || $profile['workers_max'] > self::MAX_WORKERS
            || !is_string($profile['risk_category'])
            || !in_array($profile['risk_category'], self::RISK_CATEGORIES, true)
            || self::date($referenceDate) === null
            || self::date($profile['reference_date']) === null
            || $profile['reference_date'] !== $referenceDate
        ) {
            return $result;
        }

        // A complete *synthetic shape* is still not evidence of compliance.
        return self::review(true);
    }

    /** @return array{state:string,requires_professional_review:true,synthetic_only:true,structurally_reviewable:bool} */
    private static function review(bool $valid): array
    {
        return [
            'state' => $valid ? 'EN_REVISION' : 'REQUIERE_REVISION',
            'requires_professional_review' => true,
            'synthetic_only' => true,
            'structurally_reviewable' => $valid,
        ];
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $date : null;
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string> $expected
     */
    private static function exactKeys(array $payload, array $expected): bool
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }
}

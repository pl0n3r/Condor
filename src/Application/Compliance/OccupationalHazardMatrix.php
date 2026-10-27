<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * M03 S02-S04: pure, synthetic-only hazard/control/inspection projection.
 * Risk bands are fictional exercises, NOT legal SG-SST ratings or approvals.
 * No worker records, medical details, I/O, persistence or clock dependency.
 */
final class OccupationalHazardMatrix
{
    private const MATRIX_KEYS = [
        'fixture_ref', 'tenant_ref', 'legal_entity_ref', 'hazards', 'inspections',
    ];
    private const HAZARD_KEYS = [
        'hazard_ref', 'tenant_ref', 'legal_entity_ref', 'probability', 'severity', 'controls',
    ];
    private const CONTROL_KEYS = [
        'control_ref', 'tenant_ref', 'legal_entity_ref', 'hierarchy', 'status',
    ];
    private const INSPECTION_KEYS = [
        'inspection_ref', 'tenant_ref', 'legal_entity_ref', 'hazard_ref', 'scheduled_on', 'status',
    ];
    private const HIERARCHY = [
        'ELIMINACION' => 0,
        'SUSTITUCION' => 1,
        'INGENIERIA' => 2,
        'ADMINISTRATIVO' => 3,
        'EPP' => 4,
    ];
    private const MAX_HAZARDS = 50;
    private const MAX_CONTROLS = 12;
    private const MAX_INSPECTIONS = 100;

    /**
     * @param array<array-key,mixed> $matrix Entirely discarded synthetic fixtures
     * @return array<string,mixed> No personal, contact or tenant identifiers are echoed
     */
    public static function project(array $matrix, string $tenantRef, string $legalEntityRef): array
    {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::fixture($legalEntityRef, 'legal-entity')
            || !self::exactKeys($matrix, self::MATRIX_KEYS)
            || !self::fixture($matrix['fixture_ref'], 'sgsst-hazard-matrix')
            || $matrix['tenant_ref'] !== $tenantRef
            || $matrix['legal_entity_ref'] !== $legalEntityRef
            || !self::listUpTo($matrix['hazards'], self::MAX_HAZARDS)
            || !self::listUpTo($matrix['inspections'], self::MAX_INSPECTIONS)
        ) {
            return self::rejected();
        }

        $hazards = [];
        $seenHazards = [];
        $seenControls = [];
        foreach ($matrix['hazards'] as $hazard) {
            if (!is_array($hazard) || !self::exactKeys($hazard, self::HAZARD_KEYS)
                || !self::fixture($hazard['hazard_ref'], 'sgsst-hazard')
                || $hazard['tenant_ref'] !== $tenantRef
                || $hazard['legal_entity_ref'] !== $legalEntityRef
                || !self::score($hazard['probability'])
                || !self::score($hazard['severity'])
                || !self::listUpTo($hazard['controls'], self::MAX_CONTROLS)
                || isset($seenHazards[$hazard['hazard_ref']])
            ) {
                return self::rejected();
            }
            $seenHazards[$hazard['hazard_ref']] = true;
            $controls = [];
            foreach ($hazard['controls'] as $control) {
                if (!is_array($control) || !self::exactKeys($control, self::CONTROL_KEYS)
                    || !self::fixture($control['control_ref'], 'sgsst-control')
                    || $control['tenant_ref'] !== $tenantRef
                    || $control['legal_entity_ref'] !== $legalEntityRef
                    || !is_string($control['hierarchy'])
                    || !array_key_exists($control['hierarchy'], self::HIERARCHY)
                    || !in_array($control['status'], ['PROPUESTO', 'REQUIERE_REVISION'], true)
                    || isset($seenControls[$control['control_ref']])
                ) {
                    return self::rejected();
                }
                $seenControls[$control['control_ref']] = true;
                $controls[] = [
                    'control_ref' => $control['control_ref'],
                    'hierarchy' => $control['hierarchy'],
                    'state' => $control['status'],
                ];
            }
            // Independent of the order of fixture input and ties.
            usort($controls, static function (array $a, array $b): int {
                return (self::HIERARCHY[$a['hierarchy']] <=> self::HIERARCHY[$b['hierarchy']])
                    ?: strcmp($a['control_ref'], $b['control_ref']);
            });
            $value = $hazard['probability'] * $hazard['severity'];
            $hazards[] = [
                'hazard_ref' => $hazard['hazard_ref'],
                'risk_score_synthetic' => $value,
                'risk_band_synthetic' => $value <= 5 ? 'BAJO_SINTETICO'
                    : ($value <= 12 ? 'MEDIO_SINTETICO' : 'ALTO_SINTETICO'),
                'state' => 'REQUIERE_REVISION',
                'controls' => $controls,
            ];
        }
        usort($hazards, static fn (array $a, array $b): int => strcmp($a['hazard_ref'], $b['hazard_ref']));

        $inspections = [];
        $seenInspections = [];
        foreach ($matrix['inspections'] as $inspection) {
            if (!is_array($inspection) || !self::exactKeys($inspection, self::INSPECTION_KEYS)
                || !self::fixture($inspection['inspection_ref'], 'sgsst-inspection')
                || $inspection['tenant_ref'] !== $tenantRef
                || $inspection['legal_entity_ref'] !== $legalEntityRef
                || !self::fixture($inspection['hazard_ref'], 'sgsst-hazard')
                || !isset($seenHazards[$inspection['hazard_ref']])
                || !self::date($inspection['scheduled_on'])
                || !in_array($inspection['status'], ['PROGRAMADA', 'REQUIERE_REVISION'], true)
                || isset($seenInspections[$inspection['inspection_ref']])
            ) {
                return self::rejected();
            }
            $seenInspections[$inspection['inspection_ref']] = true;
            $inspections[] = [
                'inspection_ref' => $inspection['inspection_ref'],
                'hazard_ref' => $inspection['hazard_ref'],
                'scheduled_on' => $inspection['scheduled_on'],
                'state' => $inspection['status'],
            ];
        }
        usort($inspections, static fn (array $a, array $b): int => strcmp($a['inspection_ref'], $b['inspection_ref']));

        return [
            'state' => 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'hazard_count' => count($hazards),
            'inspection_count' => count($inspections),
            'hazards' => $hazards,
            'inspections' => $inspections,
        ];
    }

    /** @return array<string,mixed> */
    private static function rejected(): array
    {
        return [
            'state' => 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'hazard_count' => 0,
            'inspection_count' => 0,
            'hazards' => [],
            'inspections' => [],
        ];
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /** @param list<string> $required @param array<array-key,mixed> $row */
    private static function exactKeys(array $row, array $required): bool
    {
        return count($row) === count($required)
            && array_diff(array_keys($row), $required) === [];
    }

    private static function listUpTo(mixed $value, int $limit): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= $limit;
    }

    private static function score(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 5;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}

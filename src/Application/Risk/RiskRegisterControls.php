<?php

declare(strict_types=1);

namespace App\Application\Risk;

/**
 * M12 S01-S05: pure, tenant-scoped synthetic risk register with offline controls.
 * Scores are illustrative, not a legal assessment or an approval decision.
 */
final class RiskRegisterControls
{
    private const MAX_ITEMS = 50;
    private const ROOT_KEYS = ['tenant_ref', 'risks', 'controls'];
    private const RISK_KEYS = ['tenant_ref', 'risk_ref', 'category', 'probability', 'impact', 'state'];
    private const CONTROL_KEYS = ['tenant_ref', 'control_ref', 'risk_ref', 'effectiveness', 'state'];
    private const CATEGORIES = ['operativo', 'financiero', 'legal', 'tecnologico'];
    private const STATES = ['PENDIENTE', 'REQUIERE_REVISION'];

    /**
     * @param array<array-key,mixed> $input Synthetic-only fixtures, no persistent records
     * @return array<string,mixed> Conservative deterministic projection
     */
    public static function project(array $input, string $tenantRef): array
    {
        if (!self::fixture($tenantRef, 'tenant')
            || !self::exactKeys($input, self::ROOT_KEYS)
            || !self::fixture($input['tenant_ref'], 'tenant')
            || $input['tenant_ref'] !== $tenantRef
            || !is_array($input['risks']) || !array_is_list($input['risks'])
            || !is_array($input['controls']) || !array_is_list($input['controls'])
            || count($input['risks']) > self::MAX_ITEMS
            || count($input['controls']) > self::MAX_ITEMS
        ) {
            return self::result(false, [], []);
        }

        $risks = [];
        foreach ($input['risks'] as $risk) {
            if (!is_array($risk)
                || !self::exactKeys($risk, self::RISK_KEYS)
                || $risk['tenant_ref'] !== $tenantRef
                || !self::fixture($risk['risk_ref'], 'risk')
                || !in_array($risk['category'], self::CATEGORIES, true)
                || !is_int($risk['probability']) || $risk['probability'] < 1 || $risk['probability'] > 5
                || !is_int($risk['impact']) || $risk['impact'] < 1 || $risk['impact'] > 5
                || !self::pending($risk['state'])
                || isset($risks[$risk['risk_ref']])
            ) {
                return self::result(false, [], []);
            }
            $risks[$risk['risk_ref']] = [
                'risk_ref' => $risk['risk_ref'],
                'category' => $risk['category'],
                'probability' => $risk['probability'],
                'impact' => $risk['impact'],
                'state' => $risk['state'],
                'controls' => [],
            ];
        }

        $seenControls = [];
        foreach ($input['controls'] as $control) {
            if (!is_array($control)
                || !self::exactKeys($control, self::CONTROL_KEYS)
                || $control['tenant_ref'] !== $tenantRef
                || !self::fixture($control['control_ref'], 'risk-control')
                || !self::fixture($control['risk_ref'], 'risk')
                || !isset($risks[$control['risk_ref']])
                || !is_int($control['effectiveness'])
                || $control['effectiveness'] < 0 || $control['effectiveness'] > 100
                || !self::pending($control['state'])
                || isset($seenControls[$control['control_ref']])
            ) {
                return self::result(false, [], []);
            }
            $seenControls[$control['control_ref']] = true;
            $risks[$control['risk_ref']]['controls'][$control['control_ref']] = [
                'control_ref' => $control['control_ref'],
                'effectiveness' => $control['effectiveness'],
                'state' => $control['state'],
            ];
        }

        ksort($risks, SORT_STRING);
        $rows = [];
        $requiresReview = false;
        foreach ($risks as $risk) {
            ksort($risk['controls'], SORT_STRING);
            $inherent = $risk['probability'] * $risk['impact'];
            $residual = $inherent;
            $controlRows = [];
            foreach ($risk['controls'] as $control) {
                // Round up: a synthetic control never eliminates all residual risk.
                $residual = max(1, intdiv($residual * (100 - $control['effectiveness']) + 99, 100));
                $controlRows[] = $control;
                $requiresReview = $requiresReview || $control['state'] === 'REQUIERE_REVISION';
            }
            $requiresReview = $requiresReview || $risk['state'] === 'REQUIERE_REVISION';
            $rows[] = [
                'risk_ref' => $risk['risk_ref'],
                'category' => $risk['category'],
                'probability' => $risk['probability'],
                'impact' => $risk['impact'],
                'inherent_score' => $inherent,
                'inherent_level' => self::level($inherent),
                'residual_score' => $residual,
                'residual_level' => self::level($residual),
                'state' => $risk['state'],
                'controls' => $controlRows,
            ];
        }
        return self::result(true, $rows, self::categories($rows), $requiresReview);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array{count:int,inherent_total:int,residual_total:int}> */
    private static function categories(array $rows): array
    {
        $summary = [];
        foreach (self::CATEGORIES as $category) {
            $summary[$category] = ['count' => 0, 'inherent_total' => 0, 'residual_total' => 0];
        }
        foreach ($rows as $row) {
            $category = $row['category'];
            $summary[$category]['count']++;
            $summary[$category]['inherent_total'] += $row['inherent_score'];
            $summary[$category]['residual_total'] += $row['residual_score'];
        }
        return $summary;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $categories @return array<string,mixed> */
    private static function result(
        bool $valid, array $rows, array $categories, bool $requiresReview = false
    ): array {
        return [
            'state' => $valid && !$requiresReview ? 'PENDIENTE' : 'REQUIERE_REVISION',
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'structurally_reviewable' => $valid,
            'risk_count' => count($rows),
            'risks' => $rows,
            'categories' => $categories,
        ];
    }

    private static function level(int $score): string
    {
        return $score >= 15 ? 'ALTO' : ($score >= 6 ? 'MEDIO' : 'BAJO');
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

    /** @param array<array-key,mixed> $payload @param list<string> $expected */
    private static function exactKeys(array $payload, array $expected): bool
    {
        return count($payload) === count($expected)
            && array_diff(array_keys($payload), $expected) === [];
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use InvalidArgumentException;

/** M09/S06: pure synthetic projection. No real suppliers, persistence or awards. */
final class SupplierEvaluationPreview
{
    private const METRICS = ['quality', 'delivery', 'compliance'];

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public static function preview(array $payload): array
    {
        self::exactKeys($payload, ['tenant_ref', 'legal_entity_ref', 'site_ref', 'supplier_ref', 'mode', 'criteria']);
        self::fixture($payload['tenant_ref'], 'tenant');
        self::fixture($payload['legal_entity_ref'], 'legal-entity');
        self::fixture($payload['site_ref'], 'site');
        self::fixture($payload['supplier_ref'], 'supplier');
        if ($payload['mode'] !== 'preview_only') {
            throw new InvalidArgumentException('PREVIEW_ONLY');
        }
        $submitted = $payload['criteria'];
        if (!is_array($submitted) || !array_is_list($submitted) || count($submitted) !== 3) {
            throw new InvalidArgumentException('INVALID_CRITERIA');
        }
        $seen = [];
        $scores = [];
        $sum = 0;
        foreach ($submitted as $criterion) {
            if (!is_array($criterion) || array_is_list($criterion)) {
                throw new InvalidArgumentException('INVALID_CRITERION');
            }
            self::exactKeys($criterion, ['metric', 'score', 'tenant_ref', 'legal_entity_ref', 'site_ref']);
            $metric = $criterion['metric'];
            if (!is_string($metric) || !in_array($metric, self::METRICS, true) || isset($seen[$metric])) {
                throw new InvalidArgumentException('INVALID_METRIC');
            }
            foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $scope) {
                if ($criterion[$scope] !== $payload[$scope]) {
                    throw new InvalidArgumentException('SCOPE_MISMATCH');
                }
            }
            $score = $criterion['score'];
            if (!is_int($score) || $score < 0 || $score > 5) {
                throw new InvalidArgumentException('INVALID_SCORE');
            }
            $seen[$metric] = true;
            $scores[$metric] = $score;
            $sum += $score;
        }
        // Independent of input order; never a selection, ranking or award decision.
        ksort($scores);
        return [
            'tenant_ref' => $payload['tenant_ref'],
            'legal_entity_ref' => $payload['legal_entity_ref'],
            'site_ref' => $payload['site_ref'],
            'supplier_ref' => $payload['supplier_ref'],
            'status' => 'REQUIRES_HUMAN_REVIEW',
            'mode' => 'preview_only',
            'scores' => $scores,
            'score_percent' => intdiv($sum * 100 + 7, 15),
            'can_award' => false,
            'can_approve' => false,
            'can_order' => false,
            'can_pay' => false,
            'persisted' => false,
            'notified' => false,
        ];
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
    }

    private static function fixture(mixed $value, string $kind): void
    {
        if (!is_string($value)
            || preg_match('~^fixture:'.preg_quote($kind, '~').'/[a-z0-9][a-z0-9-]{0,63}$~D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
    }
}

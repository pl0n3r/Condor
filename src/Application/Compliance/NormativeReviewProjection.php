<?php

declare(strict_types=1);

namespace App\Application\Compliance;

require_once __DIR__ . '/NormativeApplicability.php';
require_once __DIR__ . '/NormativeMatrix.php';

/**
 * F1b: read-only, in-memory review projection of synthetic F1a rules.
 *
 * The only authority for an applicability result is NormativeMatrix::evaluate.
 * A supplied evidence reference is NOT proof of legal compliance.
 */
final class NormativeReviewProjection
{
    private const MAX_REVIEWS = 100;
    private const REVIEW_KEYS = [
        'evidence_ref', 'municipio', 'reviewed_on', 'reviewer_ref',
        'row_index', 'state', 'tenant_ref',
    ];

    /**
     * @param list<mixed> $rules Synthetic F0 rules only
     * @param array<string, mixed> $context Synthetic tenant profile
     * @param array<array-key,mixed> $reviews Opaque synthetic review references, not attachments
     * @return array{tenant_ref:?string,municipio:?string,rows:list<array<string,mixed>>}
     */
    public static function evaluate(
        array $rules,
        array $context,
        string $referenceDate,
        array $reviews
    ): array {
        $matrix = NormativeMatrix::evaluate($rules, $context, $referenceDate);
        $scope = [
            'tenant_ref' => $matrix['tenant_ref'],
            'municipio' => $matrix['municipio'],
        ];
        if (!self::identifier($scope['tenant_ref'])
            || !self::identifier($scope['municipio'])
            || !self::date($referenceDate)
            || !array_is_list($reviews)
            || count($reviews) > self::MAX_REVIEWS
        ) {
            return [...$scope, 'rows' => [self::row('REQUIERE_REVISION')]];
        }

        $matching = [];
        $ambiguous = [];
        foreach ($reviews as $candidate) {
            if (!is_array($candidate)
                || ($candidate['tenant_ref'] ?? null) !== $scope['tenant_ref']
                || ($candidate['municipio'] ?? null) !== $scope['municipio']
            ) {
                // Never disclose that another tenant or municipality submitted a review.
                continue;
            }
            $index = $candidate['row_index'] ?? null;
            if (!is_int($index) || $index < 0 || $index >= count($matrix['rows'])) {
                continue;
            }
            if (array_key_exists($index, $matching)) {
                $ambiguous[$index] = true;
                continue;
            }
            $matching[$index] = $candidate;
        }

        $rows = [];
        foreach ($matrix['rows'] as $index => $source) {
            if (!is_array($source['source'] ?? null)
                || !self::date($source['verified_on'] ?? null)
                || !self::date($source['effective_from'] ?? null)
                || !self::date($source['effective_until'] ?? null)
                || !self::identifier($source['reviewer_ref'] ?? null)
            ) {
                $rows[] = self::row('REQUIERE_REVISION');
                continue;
            }
            if (($source['kind'] ?? null) === 'NO_APLICA'
                && ($source['applicability'] ?? null) === 'NO_APLICA'
            ) {
                $rows[] = self::row('NO_APLICA', $source);
                continue;
            }
            if (($source['applicability'] ?? null) !== 'APLICA') {
                $rows[] = self::row('REQUIERE_REVISION');
                continue;
            }
            if (isset($ambiguous[$index])) {
                $rows[] = self::row('REQUIERE_REVISION');
                continue;
            }

            $review = $matching[$index] ?? null;
            if ($review === null) {
                $rows[] = self::row('PENDIENTE', $source);
                continue;
            }
            if (!self::exactKeys($review, self::REVIEW_KEYS)
                || $review['state'] !== 'EN_REVISION'
                || !self::identifier($review['reviewer_ref'])
                || !is_string($review['evidence_ref'])
                || preg_match('/^fixture:evidence\/[a-z0-9][a-z0-9_-]{0,47}$/iD', $review['evidence_ref']) !== 1
            ) {
                $rows[] = self::row('REQUIERE_REVISION');
                continue;
            }
            $at = self::date($review['reviewed_on']);
            $verified = self::date($source['verified_on']);
            $starts = self::date($source['effective_from']);
            $ends = self::date($source['effective_until']);
            $reference = self::date($referenceDate);
            if ($at === null || $starts === null
                || $ends === null || $reference === null
                || $at < $verified || $at < $starts || $at > $ends || $at > $reference
            ) {
                $rows[] = self::row('REQUIERE_REVISION');
                continue;
            }

            $rows[] = [
                ...self::row('EN_REVISION', $source),
                'evidence_ref' => $review['evidence_ref'],
                'reviewer_ref' => $review['reviewer_ref'],
                'reviewed_on' => $review['reviewed_on'],
            ];
        }

        return [...$scope, 'rows' => $rows];
    }

    /**
     * @param array<string,mixed>|null $source
     * @return array<string,mixed>
     */
    private static function row(string $state, ?array $source = null): array
    {
        return [
            'state' => $state,
            'source' => $source['source'] ?? null,
            'normative_reviewer_ref' => $source['reviewer_ref'] ?? null,
            'verified_on' => $source['verified_on'] ?? null,
            'effective_from' => $source['effective_from'] ?? null,
            'effective_until' => $source['effective_until'] ?? null,
            'evidence_ref' => null,
            'reviewer_ref' => null,
            'reviewed_on' => null,
        ];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/iD', $value) === 1
            && !in_array(strtolower($value), [
                'unknown', 'desconocido', 'pendiente', 'sin_dato', 'no_definido',
            ], true);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string> $expected
     */
    private static function exactKeys(array $payload, array $expected): bool
    {
        $a = array_keys($payload);
        sort($a);
        sort($expected);
        return $a === $expected;
    }
}

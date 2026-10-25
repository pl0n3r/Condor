<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * F2a: pure, synthetic-only attention candidates over an F1b review projection.
 *
 * This is not a legal determination. In particular, the F1b state PENDIENTE
 * can hide structurally invalid review input, so it is NEVER treated as an
 * unambiguous absence of review or evidence of compliance here.
 */
final class ComplianceAttentionProjection
{
    private const MAX_ROWS = 100;
    private const MAX_DEADLINES = 100;
    private const DEADLINE_KEYS = [
        'deadline_ref', 'due_on', 'municipio', 'row_index',
        'source_version', 'tenant_ref', 'verified_on',
    ];

    /**
     * @param array<string,mixed> $f1bProjection An offline F1b result, not a legal opinion
     * @param array<array-key,mixed> $deadlines Only discarded synthetic fixtures
     * @return array{tenant_ref:?string,municipio:?string,rows:list<array<string,mixed>>}
     */
    public static function project(
        array $f1bProjection,
        array $deadlines,
        string $referenceDate
    ): array {
        $tenant = $f1bProjection['tenant_ref'] ?? null;
        $municipio = $f1bProjection['municipio'] ?? null;
        $scope = [
            'tenant_ref' => self::identifier($tenant) ? $tenant : null,
            'municipio' => self::identifier($municipio) ? $municipio : null,
        ];
        $reference = self::date($referenceDate);
        $rows = $f1bProjection['rows'] ?? null;
        if ($scope['tenant_ref'] === null || $scope['municipio'] === null
            || $reference === null || !is_array($rows) || !array_is_list($rows)
            || count($rows) > self::MAX_ROWS
            || !array_is_list($deadlines) || count($deadlines) > self::MAX_DEADLINES
        ) {
            return [...$scope, 'rows' => [self::attention('REQUIERE_REVISION')]];
        }

        $matching = [];
        $ambiguous = [];
        foreach ($deadlines as $candidate) {
            if (!is_array($candidate)
                || ($candidate['tenant_ref'] ?? null) !== $tenant
                || ($candidate['municipio'] ?? null) !== $municipio
            ) {
                // Foreign and unscoped references are neither returned nor counted.
                continue;
            }
            $index = $candidate['row_index'] ?? null;
            if (!is_int($index) || $index < 0 || $index >= count($rows)) {
                // Malformed same-scope input invalidates the projection, never hides.
                return [...$scope, 'rows' => [self::attention('REQUIERE_REVISION')]];
            }
            if (isset($matching[$index])) {
                $ambiguous[$index] = true;
            }
            $matching[$index] = $candidate;
        }

        $output = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $output[] = self::attention('REQUIERE_REVISION');
                continue;
            }
            $state = $row['state'] ?? null;
            // PENDIENTE may mean malformed input discarded upstream: fail closed.
            if (!in_array($state, ['EN_REVISION', 'NO_APLICA'], true)
                || isset($ambiguous[$index]) || !self::trustedF1bRow($row, $reference)
            ) {
                $output[] = self::attention('REQUIERE_REVISION');
                continue;
            }
            if ($state === 'NO_APLICA') {
                // Scoped synthetic status only, not an applicability certificate.
                $output[] = self::attention('NO_APLICA');
                continue;
            }

            $deadline = $matching[$index] ?? null;
            if (!is_array($deadline)
                || !self::exactKeys($deadline, self::DEADLINE_KEYS)
                || !self::identifier($deadline['source_version'])
                || ($row['source']['version'] ?? null) !== $deadline['source_version']
                || ($row['verified_on'] ?? null) !== $deadline['verified_on']
                || !is_string($deadline['deadline_ref'])
                || preg_match('~^fixture:deadline/[a-z0-9][a-z0-9_-]{0,47}$~iD', $deadline['deadline_ref']) !== 1
            ) {
                $output[] = self::attention('REQUIERE_REVISION');
                continue;
            }
            $due = self::date($deadline['due_on']);
            $verified = self::date($deadline['verified_on']);
            $start = self::date($row['effective_from'] ?? null);
            $end = self::date($row['effective_until'] ?? null);
            if ($due === null || $verified === null || $start === null || $end === null
                || $verified > $reference || $due < $verified
                || $due < $start || $due > $end || $start > $end
            ) {
                $output[] = self::attention('REQUIERE_REVISION');
                continue;
            }

            $output[] = [
                ...self::attention('EN_REVISION'),
                'deadline_ref' => $deadline['deadline_ref'],
                'due_on' => $deadline['due_on'],
                // Candidate for human attention, never an emitted notification.
                'attention_candidate' => $due <= $reference,
            ];
        }
        return [...$scope, 'rows' => $output];
    }

    /** @param array<string,mixed> $row */
    private static function trustedF1bRow(array $row, \DateTimeImmutable $reference): bool
    {
        $source = $row['source'] ?? null;
        if (!is_array($source)
            || !self::identifier($source['version'] ?? null)
            || !self::identifier($row['normative_reviewer_ref'] ?? null)
        ) {
            return false;
        }
        $verified = self::date($row['verified_on'] ?? null);
        $start = self::date($row['effective_from'] ?? null);
        $end = self::date($row['effective_until'] ?? null);
        if ($verified === null || $start === null || $end === null
            || $verified > $reference || $start > $reference || $end < $reference
            || $verified < $start || $verified > $end
        ) {
            return false;
        }
        if (($row['state'] ?? null) === 'EN_REVISION') {
            $reviewed = self::date($row['reviewed_on'] ?? null);
            return $reviewed !== null && $reviewed >= $verified
                && $reviewed >= $start && $reviewed <= $reference
                && $reviewed <= $end && self::identifier($row['reviewer_ref'] ?? null)
                && is_string($row['evidence_ref'] ?? null)
                && preg_match('~^fixture:evidence/[a-z0-9][a-z0-9_-]{0,47}$~iD', $row['evidence_ref']) === 1;
        }
        return ($row['evidence_ref'] ?? null) === null;
    }

    /** @return array{state:string,deadline_ref:null,due_on:null,attention_candidate:bool} */
    private static function attention(string $state): array
    {
        return [
            'state' => $state,
            'deadline_ref' => null,
            'due_on' => null,
            'attention_candidate' => false,
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
            ? $date : null;
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

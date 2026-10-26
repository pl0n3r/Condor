<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * F2b: discarded synthetic fixtures only. No legal determination, I/O or effects.
 * An F2a EN_REVISION is not evidence that an audit is closed or compliant.
 */
final class ComplianceAuditCandidateProjection
{
    private const MAX_ROWS = 100;
    private const AUDIT_KEYS = [
        'action_ref', 'audit_ref', 'deadline_ref', 'due_on', 'municipio',
        'opened_on', 'reviewed_on', 'row_index', 'tenant_ref',
    ];
    private const UPSTREAM_ROW_KEYS = [
        'attention_candidate', 'deadline_ref', 'due_on', 'state',
    ];

    /**
     * @param array<string, mixed> $f2aProjection Synthetic F2a output only
     * @param array<array-key, mixed> $audits Synthetic candidate metadata only
     * @return array{tenant_ref:?string,municipio:?string,rows:list<array<string,mixed>>}
     */
    public static function project(
        array $f2aProjection,
        array $audits,
        string $referenceDate
    ): array {
        $tenant = $f2aProjection['tenant_ref'] ?? null;
        $municipio = $f2aProjection['municipio'] ?? null;
        $scope = [
            'tenant_ref' => self::identifier($tenant) ? $tenant : null,
            'municipio' => self::identifier($municipio) ? $municipio : null,
        ];
        $rows = $f2aProjection['rows'] ?? null;
        $reference = self::date($referenceDate);
        if ($scope['tenant_ref'] === null || $scope['municipio'] === null
            || $reference === null || !is_array($rows) || !array_is_list($rows)
            || count($rows) > self::MAX_ROWS || !array_is_list($audits)
            || count($audits) > self::MAX_ROWS
        ) {
            return [...$scope, 'rows' => [self::candidate('REQUIERE_REVISION')]];
        }

        $matching = [];
        foreach ($audits as $audit) {
            if (!is_array($audit) || !self::exactKeys($audit, self::AUDIT_KEYS)
                || !self::identifier($audit['tenant_ref'])
                || !self::identifier($audit['municipio'])
                || $audit['tenant_ref'] !== $tenant
                || $audit['municipio'] !== $municipio
                || !is_int($audit['row_index']) || $audit['row_index'] < 0
                || $audit['row_index'] >= count($rows)
                || array_key_exists($audit['row_index'], $matching)
            ) {
                // Unknown, foreign, duplicated or malformed records fail closed;
                // no tenant-specific candidate content can be returned.
                return [...$scope, 'rows' => [self::candidate('REQUIERE_REVISION')]];
            }
            $matching[$audit['row_index']] = $audit;
        }

        $result = [];
        foreach ($rows as $index => $upstream) {
            if (!is_array($upstream)
                || !self::exactKeys($upstream, self::UPSTREAM_ROW_KEYS)
                || $upstream['state'] !== 'EN_REVISION'
                || $upstream['attention_candidate'] !== true
                || !self::fixture($upstream['deadline_ref'], 'deadline')
                || ($due = self::date($upstream['due_on'])) === null
                || $due > $reference
            ) {
                $result[] = self::candidate('REQUIERE_REVISION');
                continue;
            }
            $audit = $matching[$index] ?? null;
            if (!is_array($audit)
                || !self::fixture($audit['audit_ref'], 'audit')
                || !self::fixture($audit['action_ref'], 'action')
                || $audit['deadline_ref'] !== $upstream['deadline_ref']
                || $audit['due_on'] !== $upstream['due_on']
                || ($opened = self::date($audit['opened_on'])) === null
                || ($reviewed = self::date($audit['reviewed_on'])) === null
                || $opened < $due || $reviewed < $opened
                || $reviewed > $reference
            ) {
                $result[] = self::candidate('REQUIERE_REVISION');
                continue;
            }
            $result[] = [
                ...self::candidate('EN_REVISION'),
                'audit_ref' => $audit['audit_ref'],
                'action_ref' => $audit['action_ref'],
                'corrective_action_candidate' => true,
            ];
        }
        return [...$scope, 'rows' => $result];
    }

    /** @return array{state:string,audit_ref:null,action_ref:null,corrective_action_candidate:bool} */
    private static function candidate(string $state): array
    {
        return [
            'state' => $state,
            'audit_ref' => null,
            'action_ref' => null,
            'corrective_action_candidate' => false,
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

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~iD', $value) === 1;
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

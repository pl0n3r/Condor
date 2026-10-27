<?php

declare(strict_types=1);

namespace App\Application\Documents;

/**
 * Pure synthetic-only documentary workflow. No approvals, deletions, retention
 * enforcement, I/O or persistence are performed by this projection.
 */
final class DocumentApprovalRetention
{
    private const MAX_RECORDS = 100;
    private const MAX_EVENTS = 100;
    private const RETENTION_DAYS = ['PUBLICA' => 365, 'INTERNA' => 730, 'RESERVADA' => 1095];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function project(array $input): array
    {
        $invalid = self::invalid();
        if (!self::keys($input, ['tenant_ref', 'company_ref', 'as_of', 'records'])
            || !self::tenant($input['tenant_ref'])
            || !self::ref($input['company_ref'], 'company')
            || !self::date($input['as_of'])
            || !is_array($input['records']) || !array_is_list($input['records'])
            || count($input['records']) > self::MAX_RECORDS) {
            return $invalid;
        }
        $records = [];
        $seen = [];
        foreach ($input['records'] as $record) {
            if (!is_array($record)
                || !self::keys($record, [
                    'tenant_ref', 'company_ref', 'ref', 'classification', 'author_ref', 'events',
                ])
                || !self::tenant($record['tenant_ref'])
                || !self::ref($record['company_ref'], 'company')
                || !self::ref($record['ref'], 'dossier')
                || !is_string($record['classification'])
                || !array_key_exists($record['classification'], self::RETENTION_DAYS)
                || !self::ref($record['author_ref'], 'actor')
                || !is_array($record['events']) || !array_is_list($record['events'])
                || count($record['events']) > self::MAX_EVENTS) {
                return $invalid;
            }
            $state = 'BORRADOR';
            $reviewer = null;
            $approver = null;
            $history = [];
            $lastDate = null;
            foreach ($record['events'] as $event) {
                if (!is_array($event)
                    || !self::keys($event, ['action', 'actor_ref', 'role', 'at'])
                    || !in_array($event['action'], ['REVISAR', 'APROBAR', 'ACCESO'], true)
                    || !self::ref($event['actor_ref'], 'actor')
                    || !in_array($event['role'], ['revisor', 'aprobador', 'lector'], true)
                    || !self::date($event['at'])
                    || $event['at'] > $input['as_of']
                    || ($lastDate !== null && $event['at'] < $lastDate)) {
                    return $invalid;
                }
                $lastDate = $event['at'];
                if ($event['action'] === 'REVISAR') {
                    if ($event['role'] !== 'revisor' || $state !== 'BORRADOR'
                        || $event['actor_ref'] === $record['author_ref']) {
                        return $invalid;
                    }
                    $state = 'REVISADO';
                    $reviewer = $event['actor_ref'];
                } elseif ($event['action'] === 'APROBAR') {
                    if ($event['role'] !== 'aprobador' || $state !== 'REVISADO'
                        || $event['actor_ref'] === $record['author_ref']
                        || $event['actor_ref'] === $reviewer) {
                        return $invalid;
                    }
                    $state = 'APROBADO_EXPLICITO';
                    $approver = $event['actor_ref'];
                } elseif ($event['role'] !== 'lector') {
                    return $invalid;
                }
                $history[] = [
                    'action' => $event['action'], 'actor_ref' => $event['actor_ref'],
                    'at' => $event['at'], 'state_after' => $state,
                ];
            }
            if ($record['tenant_ref'] !== $input['tenant_ref']
                || $record['company_ref'] !== $input['company_ref']) {
                continue; // A foreign tenant's records are not returned.
            }
            if (isset($seen[$record['ref']])) {
                return $invalid;
            }
            $seen[$record['ref']] = true;
            $records[] = [
                'ref' => $record['ref'], 'classification' => $record['classification'],
                'state' => $state, 'reviewer_ref' => $reviewer, 'approver_ref' => $approver,
                'history' => $history,
                'retention_proposal_days' => self::RETENTION_DAYS[$record['classification']],
                'retention_is_legal_policy' => false,
                'delete_authorized' => false,
                'automatic_deletion' => false,
                'automatic_approval' => false,
                'can_close' => false,
            ];
        }
        usort($records, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        return [
            'valid' => true, 'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'], 'state' => 'REQUIERE_REVISION',
            'can_close' => false, 'automatic_approval' => false,
            'automatic_deletion' => false, 'records' => $records,
        ];
    }

    /** @return array<string, mixed> */
    private static function invalid(): array
    {
        return [
            'valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'state' => 'REQUIERE_REVISION', 'can_close' => false,
            'automatic_approval' => false, 'automatic_deletion' => false, 'records' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expected
     */
    private static function keys(array $data, array $expected): bool
    {
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private static function tenant(mixed $value): bool
    {
        return is_string($value) && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    private static function ref(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}

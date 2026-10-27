<?php

declare(strict_types=1);

namespace App\Application\CustomerService;

use DateTimeImmutable;
use DateTimeZone;

/** Pure synthetic PQRS/SLA projection; no persistence, network or automatic decisions. */
final class CustomerCaseSla
{
    private const BATCH_KEYS = ['fixture_ref', 'tenant_ref', 'sla_hours', 'cases'];
    private const CASE_KEYS = ['case_ref', 'tenant_ref', 'type', 'priority', 'opened_at', 'status'];
    private const PRIORITIES = ['ALTA', 'MEDIA', 'BAJA'];
    private const TYPES = ['PQRS', 'RECLAMO', 'SOLICITUD'];
    private const MAX_CASES = 32;

    /**
     * @param array<array-key,mixed> $batch Fixture containing tenant-scoped SLA hours and cases
     * @return array<string,mixed> Redacted synthetic-only state; never approves or closes a case
     */
    public static function project(array $batch, string $tenantRef, string $referenceAt): array
    {
        $now = self::timestamp($referenceAt);
        if (!self::fixture($tenantRef, 'tenant')
            || !self::keys($batch, self::BATCH_KEYS)
            || !self::fixture($batch['tenant_ref'], 'tenant')
            || $batch['tenant_ref'] !== $tenantRef
            || !self::fixture($batch['fixture_ref'], 'pqrs-batch')
            || !is_array($batch['sla_hours'])
            || !self::keys($batch['sla_hours'], self::PRIORITIES)
            || !is_array($batch['cases'])
            || !array_is_list($batch['cases'])
            || count($batch['cases']) > self::MAX_CASES
            || $now === null
        ) {
            return self::result('REQUIERE_REVISION', false, []);
        }

        foreach (self::PRIORITIES as $priority) {
            $hours = $batch['sla_hours'][$priority];
            if (!is_int($hours) || $hours < 1 || $hours > 72) {
                return self::result('REQUIERE_REVISION', false, []);
            }
        }

        $seen = [];
        $cases = [];
        $needsReview = false;
        foreach ($batch['cases'] as $case) {
            if (!is_array($case)
                || !self::keys($case, self::CASE_KEYS)
                || !self::fixture($case['case_ref'], 'pqrs-case')
                || !self::fixture($case['tenant_ref'], 'tenant')
                || $case['tenant_ref'] !== $tenantRef
                || !is_string($case['type'])
                || !in_array($case['type'], self::TYPES, true)
                || !is_string($case['priority'])
                || !in_array($case['priority'], self::PRIORITIES, true)
                || !is_string($case['status'])
                || !in_array($case['status'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
                || !is_string($case['opened_at'])
                || isset($seen[$case['case_ref']])
            ) {
                return self::result('REQUIERE_REVISION', false, []);
            }
            $opened = self::timestamp($case['opened_at']);
            if ($opened === null || $opened > $now) {
                return self::result('REQUIERE_REVISION', false, []);
            }
            $seen[$case['case_ref']] = true;
            $due = self::dueAt($opened, $batch['sla_hours'][$case['priority']]);
            $overdue = $now > $due;
            $state = $case['status'] === 'REQUIERE_REVISION' || $overdue
                ? 'REQUIERE_REVISION' : 'PENDIENTE';
            $needsReview = $needsReview || $state === 'REQUIERE_REVISION';
            $cases[] = [
                'case_ref' => $case['case_ref'],
                'type' => $case['type'],
                'priority' => $case['priority'],
                'state' => $state,
                'sla_due_at' => $due->format('Y-m-d\TH:i:s\Z'),
                'alert' => $overdue ? 'VENCIDO' : 'EN_PLAZO',
            ];
        }
        return self::result($needsReview ? 'REQUIERE_REVISION' : 'PENDIENTE', true, $cases);
    }

    /**
     * @param list<array<string,mixed>> $cases
     * @return array<string,mixed>
     */
    private static function result(string $state, bool $reviewable, array $cases): array
    {
        return [
            'state' => $state,
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'structurally_reviewable' => $reviewable,
            'case_count' => count($cases),
            'cases' => $cases,
        ];
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $expected
     */
    private static function keys(array $value, array $expected): bool
    {
        return count($value) === count($expected)
            && array_diff(array_keys($value), $expected) === [];
    }

    private static function timestamp(string $value): ?DateTimeImmutable
    {
        if (preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~D', $value) !== 1) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        return $dt !== false && $dt->format('Y-m-d\TH:i:s\Z') === $value ? $dt : null;
    }

    /** Synthetic business hours: Monday-Friday 09:00-17:00 America/Bogota, no holidays. */
    private static function dueAt(DateTimeImmutable $start, int $hours): DateTimeImmutable
    {
        $local = $start->setTimezone(new DateTimeZone('America/Bogota'));
        $remaining = $hours * 60;
        while ($remaining > 0) {
            if ((int) $local->format('N') > 5) {
                $local = $local->modify('next monday')->setTime(9, 0);
                continue;
            }
            $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
            if ($minute < 540) {
                $local = $local->setTime(9, 0);
                continue;
            }
            if ($minute >= 1020) {
                $local = $local->modify('+1 day')->setTime(9, 0);
                continue;
            }
            $segment = min($remaining, 1020 - $minute);
            $local = $local->modify('+' . $segment . ' minutes');
            $remaining -= $segment;
        }
        return $local->setTimezone(new DateTimeZone('UTC'));
    }
}

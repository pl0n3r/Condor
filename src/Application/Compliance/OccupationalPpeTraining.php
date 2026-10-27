<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * M03 S07-S10: offline, synthetic SG-SST previews; never legal compliance.
 * Does not persist, notify, approve or close anything. All identities are fake.
 */
final class OccupationalPpeTraining
{
    private const ACTIVITY_TYPES = [
        'EPP_ENTREGA', 'EPP_REPOSICION', 'CAPACITACION',
        'SIMULACRO_EMERGENCIA', 'COMITE_VIGIA',
    ];
    private const INPUT_KEYS = ['activities', 'company_ref', 'reference_date', 'tenant_ref'];
    private const ACTIVITY_KEYS = [
        'activity_ref', 'attendee_refs', 'company_ref', 'due_on', 'evidence_ref',
        'kind', 'occurred_on', 'review_state', 'tenant_ref',
    ];
    private const MAX_ACTIVITIES = 100;
    private const MAX_ATTENDEES = 50;

    /**
     * @param array<string,mixed> $input Only discarded, explicitly synthetic fixtures
     * @return array<string,mixed> Conservative previews and manual follow-up items
     */
    public static function project(array $input): array
    {
        if (!self::exactKeys($input, self::INPUT_KEYS)
            || !self::fixture($input['tenant_ref'], 'tenant')
            || !self::fixture($input['company_ref'], 'company')
            || !is_array($input['activities'])
            || !array_is_list($input['activities'])
            || count($input['activities']) > self::MAX_ACTIVITIES
        ) {
            throw new \InvalidArgumentException('Non-synthetic or ambiguous SG-SST input');
        }

        $reference = self::date($input['reference_date']);
        if ($reference === null) {
            throw new \InvalidArgumentException('Invalid reference date');
        }

        $rows = [];
        $pending = [];
        $seen = [];
        foreach ($input['activities'] as $item) {
            if (!is_array($item) || !self::exactKeys($item, self::ACTIVITY_KEYS)
                || !self::fixture($item['tenant_ref'], 'tenant')
                || !self::fixture($item['company_ref'], 'company')
                || !self::fixture($item['activity_ref'], 'activity')
                || !in_array($item['kind'], self::ACTIVITY_TYPES, true)
                || !in_array($item['review_state'], ['PENDIENTE', 'EN_REVISION'], true)
                || ($item['evidence_ref'] !== null && !self::fixture($item['evidence_ref'], 'evidence'))
                || !is_array($item['attendee_refs'])
                || !array_is_list($item['attendee_refs'])
                || count($item['attendee_refs']) > self::MAX_ATTENDEES
            ) {
                throw new \InvalidArgumentException('Invalid SG-SST synthetic activity');
            }
            foreach ($item['attendee_refs'] as $attendee) {
                if (!self::fixture($attendee, 'participant')) {
                    throw new \InvalidArgumentException('Personal data is not allowed');
                }
            }

            // Never expose or process another tenant/company's activities.
            if ($item['tenant_ref'] !== $input['tenant_ref']
                || $item['company_ref'] !== $input['company_ref']) {
                continue;
            }
            if (isset($seen[$item['activity_ref']])) {
                throw new \InvalidArgumentException('Duplicate synthetic activity');
            }
            $seen[$item['activity_ref']] = true;

            $occurred = $item['occurred_on'] === null ? null : self::date($item['occurred_on']);
            $due = self::date($item['due_on']);
            if ($due === null || ($item['occurred_on'] !== null && $occurred === null)
                || ($occurred !== null && $occurred > $reference)
                || ($occurred !== null && $occurred > $due)
            ) {
                throw new \InvalidArgumentException('Invalid synthetic activity dates');
            }
            $needsAttendees = $item['kind'] === 'CAPACITACION';
            if (!$needsAttendees && $item['attendee_refs'] !== []) {
                throw new \InvalidArgumentException('Attendance is only for training');
            }
            if (count($item['attendee_refs']) !== count(array_unique($item['attendee_refs']))) {
                throw new \InvalidArgumentException('Duplicate synthetic participants');
            }

            $state = 'PENDIENTE';
            $reason = 'REVISION_PROFESIONAL_PENDIENTE';
            if ($item['evidence_ref'] === null || $occurred === null
                || ($needsAttendees && $item['attendee_refs'] === [])) {
                $state = 'SIN_EVIDENCIA';
                $reason = 'EVIDENCIA_O_ASISTENCIA_NO_VERIFICADA';
            } elseif ($due < $reference) {
                $state = 'VENCIDA';
                $reason = 'VIGENCIA_REQUIERE_REVISION';
            } elseif ((int) $reference->diff($due)->format('%a') <= 30) {
                $state = 'POR_VENCER';
                $reason = 'REPOSICION_O_REVISION_PROXIMA';
            }
            // No CUMPLE/APROBADO/COMPLETADO is inferred from a fictional upload.
            $rows[] = [
                'activity_ref' => $item['activity_ref'],
                'kind' => $item['kind'],
                'state' => $state,
                'requires_professional_review' => true,
                'synthetic_only' => true,
                'automatically_approved' => false,
                'automatically_closed' => false,
            ];
            $pending[] = [
                'activity_ref' => $item['activity_ref'],
                'reason' => $reason,
            ];
        }
        return [
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
            'rows' => $rows,
            'pending_evaluations' => $pending,
            'synthetic_only' => true,
            'automatic_approval' => false,
            'automatic_closure' => false,
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

    /** @param array<array-key,mixed> $data @param list<string> $keys */
    private static function exactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys)
            && array_diff(array_keys($data), $keys) === [];
    }
}

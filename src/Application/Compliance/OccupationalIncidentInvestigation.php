<?php

declare(strict_types=1);

namespace App\Application\Compliance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure SG-SST incident/investigation projection. Synthetic fixtures only; not a legal finding. */
final class OccupationalIncidentInvestigation
{
    private const MAX_ROWS = 100;
    private const MAX_ACTIONS = 50;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function project(array $input): array
    {
        self::exact($input, ['tenant_ref', 'reference_date', 'incidents', 'investigations', 'sensitive_fixtures']);
        $tenant = self::ref($input['tenant_ref'], 'tenant');
        $reference = self::date($input['reference_date']);
        $incidents = [];
        foreach (self::rows($input['incidents']) as $row) {
            self::exact($row, ['tenant_ref', 'incident_ref', 'kind', 'occurred_on', 'reported_on', 'summary_ref']);
            $scope = self::ref($row['tenant_ref'], 'tenant');
            $id = self::ref($row['incident_ref'], 'incident');
            $summary = self::ref($row['summary_ref'], 'summary');
            $occurred = self::date($row['occurred_on']);
            $reported = self::date($row['reported_on']);
            if (!in_array($row['kind'], ['INCIDENT', 'ACCIDENT', 'NEAR_MISS'], true)
                || $reported < $occurred || $reported > $reference || $summary === '') {
                self::reject();
            }
            if ($scope !== $tenant) {
                continue;
            }
            if (isset($incidents[$id])) {
                self::reject();
            }
            $incidents[$id] = ['incident_ref' => $id, 'kind' => $row['kind'],
                'occurred_on' => $occurred, 'reported_on' => $reported];
        }

        $investigations = [];
        foreach (self::rows($input['investigations']) as $row) {
            self::exact($row, ['tenant_ref', 'incident_ref', 'started_on', 'due_on', 'deadline_ref', 'actions']);
            $scope = self::ref($row['tenant_ref'], 'tenant');
            $id = self::ref($row['incident_ref'], 'incident');
            $start = self::date($row['started_on']);
            $due = self::date($row['due_on']);
            $deadline = self::ref($row['deadline_ref'], 'deadline');
            if ($due < $start) {
                self::reject();
            }
            $actions = [];
            foreach (self::rows($row['actions'], self::MAX_ACTIONS) as $action) {
                self::exact($action, ['action_ref', 'description_ref']);
                $actionRef = self::ref($action['action_ref'], 'action');
                self::ref($action['description_ref'], 'summary');
                if (isset($actions[$actionRef])) {
                    self::reject();
                }
                $actions[$actionRef] = ['action_ref' => $actionRef, 'state' => 'PENDIENTE_REVISION'];
            }
            if ($scope !== $tenant) {
                continue;
            }
            if (!isset($incidents[$id]) || isset($investigations[$id])
                || $start < $incidents[$id]['occurred_on']) {
                self::reject();
            }
            ksort($actions);
            $investigations[$id] = ['deadline_ref' => $deadline, 'due_on' => $due,
                'deadline_attention_candidate' => $due <= $reference,
                'actions' => array_values($actions)];
        }

        $sensitive = [];
        foreach (self::rows($input['sensitive_fixtures']) as $row) {
            self::exact($row, ['tenant_ref', 'incident_ref', 'sensitive_ref']);
            $scope = self::ref($row['tenant_ref'], 'tenant');
            $id = self::ref($row['incident_ref'], 'incident');
            $private = self::ref($row['sensitive_ref'], 'sensitive');
            if ($scope !== $tenant) {
                continue;
            }
            if (!isset($incidents[$id]) || isset($sensitive[$private])) {
                self::reject();
            }
            $sensitive[$private] = true;
        }

        ksort($incidents);
        $projected = [];
        foreach ($incidents as $id => $incident) {
            $investigation = $investigations[$id] ?? null;
            $projected[] = [...$incident, 'investigation_state' => $investigation === null
                ? 'PENDIENTE_REVISION' : 'EN_REVISION',
                'deadline' => $investigation === null ? null : [
                    'deadline_ref' => $investigation['deadline_ref'],
                    'due_on' => $investigation['due_on'],
                    'attention_candidate' => $investigation['deadline_attention_candidate'],
                    'basis' => 'FIXTURE_ONLY_NOT_LEGAL',
                ],
                'actions' => $investigation['actions'] ?? [],
                'requires_professional_review' => true,
                'automatic_approval' => false,
                'automatic_closure' => false];
        }

        return ['tenant_ref' => $tenant, 'reference_date' => $reference,
            'incidents' => $projected,
            'sensitive_compartment' => ['synthetic_only' => true,
                'stored' => false, 'redacted_count' => count($sensitive)],
            'requires_professional_review' => true,
            'automatic_approval' => false, 'automatic_closure' => false];
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, int $limit = self::MAX_ROWS): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            self::reject();
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                self::reject();
            }
        }
        return $value;
    }

    /** @param array<array-key,mixed> $value @param list<string> $keys */
    private static function exact(array $value, array $keys): void
    {
        if (count($value) !== count($keys)) {
            self::reject();
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                self::reject();
            }
        }
    }

    private static function ref(mixed $value, string $kind): string
    {
        if (!is_string($value) || strlen($value) > 72
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $value) !== 1
            || preg_match('/[0-9]{7,}/', $value) === 1) {
            self::reject();
        }
        return $value;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            self::reject();
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            self::reject();
        }
        return $value;
    }

    private static function reject(): never
    {
        throw new InvalidArgumentException('Synthetic SG-SST fixture rejected.');
    }
}

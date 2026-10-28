<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DomainException;

/**
 * Registro exclusivamente sintético de solicitudes Factory/custom.
 * Una proyección pura de eventos; NO autentica usuarios, persiste datos,
 * crea Issues, altera el catálogo, habilita entitlements ni realiza cobros.
 * El sistema que lo integre debe autenticar el actor antes de construir el input.
 */
final class CustomRequestRegistry
{
    private const INPUT_FIELDS = ['classification', 'tenant_ref', 'company_ref', 'events'];
    private const EVENT_FIELDS = [
        'event_ref', 'request_ref', 'tenant_ref', 'company_ref', 'actor_ref',
        'actor_role', 'occurred_at', 'action', 'request_type', 'source',
        'priority', 'issue_ref',
    ];
    private const ROLES = ['tenant_admin', 'platform_admin'];
    private const TYPES = ['PRODUCT', 'INTEGRATION', 'CUSTOM'];
    private const SOURCES = ['FACTORY', 'CUSTOMER'];
    private const PRIORITIES = ['low', 'medium', 'high'];
    private const ACTIONS = ['CREATE', 'TRIAGE', 'PRIORITIZE', 'LINK', 'REJECT'];

    /**
     * @param array<string, mixed> $input
     * @return array{status: string, reason: ?string, requests: ?array<string, array<string, mixed>>, audit: list<array<string, string|int|null>>, can_charge: false, persisted: false}
     */
    public static function evaluate(array $input): array
    {
        try {
            self::assertFields($input, self::INPUT_FIELDS);
            if ($input['classification'] !== 'synthetic') {
                throw new DomainException('synthetic_only');
            }
            $tenant = self::reference($input['tenant_ref'], 'tenant');
            $company = self::reference($input['company_ref'], 'company');
            $events = $input['events'];
            if (!is_array($events) || !array_is_list($events) || $events === [] || count($events) > 200) {
                throw new DomainException('invalid_events');
            }
            $requests = [];
            $audit = [];
            $seenEvents = [];
            $lastTimestamp = null;
            foreach ($events as $event) {
                if (!is_array($event)) {
                    throw new DomainException('invalid_event');
                }
                self::assertFields($event, self::EVENT_FIELDS);
                if ($event['tenant_ref'] !== $tenant || $event['company_ref'] !== $company) {
                    throw new DomainException('tenant_mismatch');
                }
                $eventRef = self::reference($event['event_ref'], 'event');
                $requestRef = self::reference($event['request_ref'], 'request');
                $actorRef = self::reference($event['actor_ref'], 'actor');
                $role = self::enumValue($event['actor_role'], self::ROLES);
                $timestamp = self::timestamp($event['occurred_at']);
                $action = self::enumValue($event['action'], self::ACTIONS);
                $type = self::enumValue($event['request_type'], self::TYPES);
                $source = self::enumValue($event['source'], self::SOURCES);
                $priority = self::enumValue($event['priority'], self::PRIORITIES);
                $issue = $event['issue_ref'];
                if ($issue !== null) {
                    $issue = self::reference($issue, 'issue');
                }
                if (isset($seenEvents[$eventRef]) || ($lastTimestamp !== null && $timestamp <= $lastTimestamp)) {
                    throw new DomainException('duplicate_or_unordered_event');
                }
                $seenEvents[$eventRef] = true;
                $lastTimestamp = $timestamp;

                if ($action === 'CREATE') {
                    if (isset($requests[$requestRef]) || $issue !== null || ($role !== 'platform_admin' && $priority !== 'medium')) {
                        throw new DomainException('invalid_create');
                    }
                    $requests[$requestRef] = [
                        'request_ref' => $requestRef,
                        'tenant_ref' => $tenant,
                        'company_ref' => $company,
                        'request_type' => $type,
                        'source' => $source,
                        'status' => 'NEW',
                        'priority' => $priority,
                        'issue_ref' => null,
                    ];
                } else {
                    if (!isset($requests[$requestRef])) {
                        throw new DomainException('unknown_request');
                    }
                    $current = &$requests[$requestRef];
                    if ($current['request_type'] !== $type || $current['source'] !== $source) {
                        throw new DomainException('request_mismatch');
                    }
                    if ($action !== 'PRIORITIZE' && $current['priority'] !== $priority) {
                        throw new DomainException('priority_mismatch');
                    }
                    if ($action === 'TRIAGE') {
                        if ($current['status'] !== 'NEW' || $issue !== null) {
                            throw new DomainException('invalid_transition');
                        }
                        $current['status'] = 'TRIAGED';
                    } elseif ($action === 'PRIORITIZE') {
                        if ($role !== 'platform_admin' || $current['status'] !== 'TRIAGED' || $issue !== null) {
                            throw new DomainException('invalid_transition');
                        }
                        $current['priority'] = $priority;
                    } elseif ($action === 'LINK') {
                        if ($role !== 'platform_admin' || $current['status'] !== 'TRIAGED' || $issue === null) {
                            throw new DomainException('invalid_transition');
                        }
                        $current['issue_ref'] = $issue;
                        $current['status'] = 'LINKED';
                    } elseif ($action === 'REJECT') {
                        if ($role !== 'platform_admin' || $current['status'] !== 'TRIAGED' || $issue !== null) {
                            throw new DomainException('invalid_transition');
                        }
                        $current['status'] = 'REJECTED';
                    } else {
                        throw new DomainException('invalid_transition');
                    }
                    unset($current);
                }
                $audit[] = [
                    'event_ref' => $eventRef, 'request_ref' => $requestRef,
                    'tenant_ref' => $tenant, 'company_ref' => $company,
                    'actor_ref' => $actorRef, 'actor_role' => $role,
                    'action' => $action, 'occurred_at' => $timestamp,
                    'status' => $requests[$requestRef]['status'],
                    'priority' => $requests[$requestRef]['priority'],
                    'issue_ref' => $requests[$requestRef]['issue_ref'],
                ];
            }
            ksort($requests, SORT_STRING);
            return [
                'status' => 'valid', 'reason' => null,
                'requests' => $requests, 'audit' => $audit,
                'can_charge' => false, 'persisted' => false,
            ];
        } catch (DomainException $exception) {
            // No reflejar contenido del input; ningún estado parcial sale al exterior.
            return [
                'status' => 'unavailable', 'reason' => $exception->getMessage(),
                'requests' => null, 'audit' => [],
                'can_charge' => false, 'persisted' => false,
            ];
        }
    }

    /** @param array<array-key, mixed> $value @param list<string> $fields */
    private static function assertFields(array $value, array $fields): void
    {
        if (count($value) !== count($fields) || array_diff($fields, array_keys($value)) !== []) {
            throw new DomainException('invalid_shape');
        }
    }

    private static function reference(mixed $value, string $kind): string
    {
        if (!is_string($value) || preg_match('/^fixture:' . $kind . '\/[a-z][a-z0-9-]{0,39}$/D', $value) !== 1) {
            throw new DomainException('invalid_reference');
        }
        return $value;
    }

    /** @param list<string> $allowed */
    private static function enumValue(mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new DomainException('invalid_enum');
        }
        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('invalid_timestamp');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        if ($dt === false || $dt->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new DomainException('invalid_timestamp');
        }
        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Commercial;

/**
 * Offline, synthetic-only onboarding evidence. The normalized snapshots are
 * derived by callers from the existing commercial readers and entitlement
 * resolver; this class NEVER creates subscriptions, payments or accounts.
 */
final class OnboardingChecklist
{
    private const STEPS = ['company', 'catalog', 'entitlements', 'subscription', 'domain'];

    /**
     * @param array<string,mixed> $input Synthetic snapshots and manual approvals.
     * @return array<string,mixed>
     */
    public static function evaluate(array $input): array
    {
        $rejected = self::rejected();
        if (!self::keys($input, [
            'tenant_ref', 'company_ref', 'actor_ref', 'actor_role', 'catalog',
            'options', 'entitlements', 'subscription', 'events',
        ])) {
            return $rejected;
        }
        $tenant = $input['tenant_ref'];
        $company = $input['company_ref'];
        $actor = $input['actor_ref'];
        if (!self::ref($tenant, 'tenant') || !self::ref($company, 'company')
            || !self::ref($actor, 'actor')
            || !in_array($input['actor_role'], ['tenant_admin', 'platform_admin'], true)
        ) {
            return $rejected;
        }
        $catalog = $input['catalog'];
        $options = $input['options'];
        $entitlements = $input['entitlements'];
        $subscription = $input['subscription'];
        if (!is_array($catalog) || !self::keys($catalog, ['tenant_ref', 'company_ref', 'plan_key', 'version', 'capabilities'])
            || !is_array($options) || !self::keys($options, ['tenant_ref', 'company_ref', 'plan_key', 'version', 'capabilities'])
            || !is_array($entitlements) || !self::keys($entitlements, ['tenant_ref', 'company_ref', 'plan_key', 'version', 'capabilities'])
            || !is_array($subscription) || !self::keys($subscription, ['tenant_ref', 'company_ref', 'state'])
        ) {
            return $rejected;
        }
        foreach ([$catalog, $options, $entitlements, $subscription] as $snapshot) {
            if ($snapshot['tenant_ref'] !== $tenant || $snapshot['company_ref'] !== $company) {
                return $rejected;
            }
        }
        $planKey = $catalog['plan_key'];
        $version = $catalog['version'];
        if (!self::ref($planKey, 'plan') || !is_int($version) || $version < 1
            || $options['plan_key'] !== $planKey || $entitlements['plan_key'] !== $planKey
            || $options['version'] !== $version || $entitlements['version'] !== $version
            || !in_array($subscription['state'], ['not_configured', 'trialing', 'active', 'paused'], true)
        ) {
            return $rejected;
        }
        $capabilities = $catalog['capabilities'];
        $selected = $options['capabilities'];
        $enabled = $entitlements['capabilities'];
        if (!self::capabilityList($capabilities) || !self::capabilityList($selected)
            || !is_array($enabled) || count($enabled) > 50 || array_is_list($enabled)
        ) {
            return $rejected;
        }
        $allowed = array_fill_keys($capabilities, true);
        foreach ($selected as $key) {
            if (!isset($allowed[$key]) || ($enabled[$key] ?? null) !== true) {
                return $rejected;
            }
        }
        if (count($enabled) !== count($selected)) {
            return $rejected;
        }
        foreach ($enabled as $key => $value) {
            if (!is_string($key) || !self::ref($key, 'capability')
                || !in_array($key, $selected, true) || $value !== true) {
                return $rejected;
            }
        }
        $events = $input['events'];
        if (!is_array($events) || !array_is_list($events) || count($events) > 50) {
            return $rejected;
        }
        $confirmed = [];
        $audit = [];
        $ids = [];
        foreach ($events as $event) {
            if (!is_array($event) || !self::keys($event, [
                'request_ref', 'tenant_ref', 'company_ref', 'actor_ref', 'step', 'action', 'on',
            ]) || !self::ref($event['request_ref'], 'request')
                || $event['tenant_ref'] !== $tenant || $event['company_ref'] !== $company
                || $event['actor_ref'] !== $actor || !in_array($event['step'], self::STEPS, true)
                || $event['action'] !== 'confirm' || !self::date($event['on'])
                || isset($ids[$event['request_ref']]) || isset($confirmed[$event['step']])
            ) {
                return $rejected;
            }
            $ids[$event['request_ref']] = true;
            $confirmed[$event['step']] = true;
            $audit[] = [
                'request_ref' => $event['request_ref'],
                'tenant_ref' => $tenant, 'company_ref' => $company,
                'actor_ref' => $actor, 'action' => 'confirm', 'step' => $event['step'],
                'on' => $event['on'], 'outcome' => 'recorded_for_manual_review',
            ];
        }
        usort($audit, static fn (array $left, array $right): int =>
            [$left['on'], $left['request_ref']] <=> [$right['on'], $right['request_ref']]);
        $steps = [];
        foreach (self::STEPS as $step) {
            $steps[] = ['key' => $step, 'status' => isset($confirmed[$step])
                ? 'CONFIRMADO_PARA_REVISION' : 'PENDIENTE_REVISION'];
        }
        // Even a complete checklist is not authority to activate a tenant.
        return [
            'valid' => true, 'tenant_ref' => $tenant, 'company_ref' => $company,
            'state' => 'REQUIERE_REVISION', 'steps' => $steps, 'audit' => $audit,
            'can_activate' => false, 'can_charge' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function rejected(): array
    {
        return [
            'valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'state' => 'REQUIERE_REVISION', 'steps' => [], 'audit' => [],
            'can_activate' => false, 'can_charge' => false,
        ];
    }

    private static function ref(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /** @param mixed $values */
    private static function capabilityList(mixed $values): bool
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            return false;
        }
        $seen = [];
        foreach ($values as $value) {
            if (!self::ref($value, 'capability') || isset($seen[$value])) {
                return false;
            }
            $seen[$value] = true;
        }
        return true;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function keys(array $row, array $keys): bool
    {
        $actual = array_keys($row);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}
<?php
declare(strict_types=1);

namespace App\Application\Commercial;

/**
 * Reversible offline commercial projection. No writes, payments or activation.
 * catalog_quote and subscription must be supplied by trusted server adapters
 * based on PlanQuoteService::preview and the canonical subscription lifecycle.
 */
final class TrialAndPlanChangeService
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function evaluate(array $input): array
    {
        $denied = [
            'valid' => false, 'state' => 'RECHAZADO',
            'audit' => [], 'proposal' => null,
            'can_activate' => false, 'can_charge' => false,
        ];
        if (!self::keys($input, [
            'actor_ref', 'actor_role', 'company_ref', 'permission', 'tenant_ref',
            'catalog_quote', 'subscription', 'request',
        ])) {
            return $denied;
        }
        $tenant = $input['tenant_ref'];
        $company = $input['company_ref'];
        $actor = $input['actor_ref'];
        $quote = $input['catalog_quote'];
        $subscription = $input['subscription'];
        $request = $input['request'];
        if (
            !self::reference($tenant, 'tenant')
            || !self::reference($company, 'company')
            || !self::reference($actor, 'actor')
            || $input['actor_role'] !== 'tenant_admin'
            || $input['permission'] !== 'commercial.manage'
            || !is_array($quote)
            || !is_array($subscription)
            || !is_array($request)
            || !self::keys($quote, [
                'tenant_ref', 'company_ref', 'plan_ref', 'vertical_ref',
                'cycle', 'quantities', 'addons', 'amount_minor', 'quote_required',
            ])
            || !self::keys($subscription, [
                'tenant_ref', 'company_ref', 'state', 'plan_ref',
                'current_amount_minor', 'renews_on', 'usage_compatible',
            ])
            || !self::keys($request, [
                'request_ref', 'tenant_ref', 'company_ref', 'action',
                'plan_ref', 'vertical_ref', 'cycle', 'quantities', 'addons',
            ])
        ) {
            return $denied;
        }
        foreach ([$quote, $subscription, $request] as $row) {
            if ($row['tenant_ref'] !== $tenant || $row['company_ref'] !== $company) {
                return $denied;
            }
        }
        if (
            !self::reference($request['request_ref'], 'request')
            || !self::reference($request['plan_ref'], 'plan')
            || !self::reference($request['vertical_ref'], 'vertical')
            || !self::reference($quote['plan_ref'], 'plan')
            || !self::reference($quote['vertical_ref'], 'vertical')
            || $quote['plan_ref'] !== $request['plan_ref']
            || $quote['vertical_ref'] !== $request['vertical_ref']
            || !in_array($request['cycle'], ['monthly', 'annual'], true)
            || $quote['cycle'] !== $request['cycle']
            || $quote['quantities'] !== $request['quantities']
            || $quote['addons'] !== $request['addons']
            || !self::quantities($request['quantities'])
            || !self::addons($request['addons'])
            || !is_int($quote['amount_minor'])
            || $quote['amount_minor'] < 0
            || $quote['amount_minor'] > 1000000000
            || $quote['quote_required'] !== false
            || !is_bool($subscription['usage_compatible'])
            || ($subscription['renews_on'] !== null && (
                !is_string($subscription['renews_on'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $subscription['renews_on'])
                || !self::calendarDate($subscription['renews_on'])
            ))
            || !in_array($subscription['state'], [
                'not_configured', 'trialing', 'active',
            ], true)
        ) {
            return $denied;
        }
        $action = $request['action'];
        $current = $subscription['current_amount_minor'];
        $oldPlan = $subscription['plan_ref'];
        $state = $subscription['state'];
        $effective = 'owner_review';
        $proposalStatus = 'pending_owner_review';
        if ($action === 'trial') {
            if (
                $state !== 'not_configured'
                || $oldPlan !== null
                || $current !== null
                || $subscription['renews_on'] !== null
                || $request['plan_ref'] !== 'fixture:plan/business'
            ) {
                return $denied;
            }
        } elseif ($action === 'upgrade' || $action === 'downgrade') {
            if (
                !in_array($state, ['active', 'trialing'], true)
                || !self::reference($oldPlan, 'plan')
                || !is_int($current)
                || $current < 0
                || $current > 1000000000
                || $oldPlan === $request['plan_ref']
            ) {
                return $denied;
            }
            if ($action === 'upgrade') {
                if ($quote['amount_minor'] <= $current) {
                    return $denied;
                }
                $effective = 'after_owner_review';
            } else {
                $renewal = $subscription['renews_on'];
                if (
                    $quote['amount_minor'] >= $current
                    || !is_string($renewal)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $renewal)
                    || !self::calendarDate($renewal)
                ) {
                    return $denied;
                }
                $effective = $renewal;
                if (!$subscription['usage_compatible']) {
                    $proposalStatus = 'pending_resolution';
                }
            }
        } else {
            return $denied;
        }

        return [
            'valid' => true, 'state' => 'REQUIERE_REVISION',
            'tenant_ref' => $tenant, 'company_ref' => $company,
            'audit' => [[
                'tenant_ref' => $tenant, 'company_ref' => $company,
                'actor_ref' => $actor, 'request_ref' => $request['request_ref'],
                'action' => $action, 'outcome' => 'recorded_for_manual_review',
            ]],
            'proposal' => [
                'action' => $action, 'plan_ref' => $request['plan_ref'],
                'status' => $proposalStatus, 'effective' => $effective,
                'trial_duration_days' => $action === 'trial' ? 14 : null,
            ],
            'can_activate' => false, 'can_charge' => false,
        ];
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed
     */
    private static function keys(array $value, array $allowed): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($allowed);
        return $actual === $allowed;
    }

    private static function reference(mixed $value, string $kind): bool
    {
        return is_string($value)
            && (bool) preg_match(
                '/^fixture:' . preg_quote($kind, '/') . '\/[a-z][a-z0-9_-]{0,47}$/D',
                $value,
            )
            // A fixture prefix must never camouflage a phone/NIT/document number.
            && preg_match('/[0-9]{7,}/', $value) !== 1;
    }

    private static function calendarDate(string $date): bool
    {
        $parts = explode('-', $date);
        return count($parts) === 3
            && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private static function quantities(mixed $value): bool
    {
        if (!is_array($value) || !self::keys($value, ['users', 'locations', 'companies'])) {
            return false;
        }
        foreach ($value as $count) {
            if (!is_int($count) || $count < 1 || $count > 1000) {
                return false;
            }
        }
        return true;
    }

    private static function addons(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            return false;
        }
        $seen = [];
        foreach ($value as $item) {
            if (!self::reference($item, 'addon') || isset($seen[$item])) {
                return false;
            }
            $seen[$item] = true;
        }
        return true;
    }
}

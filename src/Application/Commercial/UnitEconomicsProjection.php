<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use DateTimeImmutable;
use DomainException;
use Throwable;

/**
 * Offline-only, read-only per-tenant commercial cost projection.
 *
 * Monetary values come ONLY from the canonical subscription's PlanVersion
 * and CostAttributionSnapshot via SaasUnitEconomics. No prices, network calls,
 * persistence, commercial mutations, or payment operations live here.
 */
final class UnitEconomicsProjection
{
    /**
     * @param list<array{subscription:Subscription,entitlements:EntitlementSnapshot,cost_evidence:array<string,mixed>}> $records
     * @return array{status:string,reason:string|null,rows:list<array<string,mixed>>}
     */
    public static function project(bool $owner, array $records, DateTimeImmutable $now): array
    {
        try {
            if (!$owner) {
                throw new DomainException('owner_required');
            }
            if (!array_is_list($records) || $records === [] || count($records) > 100) {
                throw new DomainException('invalid_records');
            }

            $seen = [];
            $rows = [];
            $incomplete = false;
            foreach ($records as $record) {
                self::exactKeys($record, ['subscription', 'entitlements', 'cost_evidence']);
                $subscription = $record['subscription'];
                $entitlements = $record['entitlements'];
                $costEvidence = $record['cost_evidence'];
                if (!$subscription instanceof Subscription || !$entitlements instanceof EntitlementSnapshot
                    || !is_array($costEvidence)) {
                    throw new DomainException('invalid_record');
                }

                $tenant = $subscription->tenantId();
                $planVersion = $subscription->planVersion();
                $plan = $planVersion->plan()->key();
                $vertical = $entitlements->verticalKey();
                if (!self::synthetic($tenant, 'tenant')
                    || !self::safeKey($plan) || !self::safeKey($vertical)
                    || $entitlements->tenantId() !== $tenant
                    || $entitlements->planKey() !== $plan
                    || $entitlements->planVersion() !== $planVersion->version()
                    || isset($seen[$tenant])) {
                    throw new DomainException('tenant_or_plan_mismatch');
                }
                $seen[$tenant] = true;

                $audits = $entitlements->overrideProvenance();
                if (!is_array($audits) || !array_is_list($audits)) {
                    throw new DomainException('invalid_audit');
                }
                foreach ($audits as $audit) {
                    if (!is_array($audit)) {
                        throw new DomainException('invalid_audit');
                    }
                    self::exactKeys($audit, ['namespace', 'key', 'value', 'reason', 'actor', 'created_at']);
                    if (!in_array($audit['namespace'], ['addon', 'capability', 'limit'], true)
                        || !self::safeKey($audit['key'])
                        || !self::synthetic($audit['actor'], 'actor')
                        || !is_string($audit['reason'])
                        || preg_match('/^synthetic_[a-z0-9_]{1,64}$/D', $audit['reason']) !== 1
                        || !(is_bool($audit['value']) || is_int($audit['value']) || $audit['value'] === null
                            || (is_string($audit['value']) && preg_match('/^synthetic_[a-z0-9_]{1,64}$/D', $audit['value']) === 1))
                        || !is_string($audit['created_at'])
                        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $audit['created_at']) !== 1) {
                        throw new DomainException('invalid_audit');
                    }
                }

                if (!self::synthetic($costEvidence['tenant_ref'] ?? null, 'tenant')
                    || $costEvidence['tenant_ref'] !== $tenant) {
                    throw new DomainException('cost_tenant_mismatch');
                }
                $costRows = $costEvidence['costs'] ?? null;
                if (!is_array($costRows) || !array_is_list($costRows)) {
                    $costRows = [];
                }
                foreach ($costRows as $cost) {
                    if (!is_array($cost) || !self::synthetic($cost['source_ref'] ?? null, 'cost')) {
                        throw new DomainException('non_synthetic_cost');
                    }
                }

                // The existing domain services provide the sole accounting and
                // exact-percentage semantics, including UNKNOWN for missing cost.
                $unit = SaasUnitEconomics::derive($subscription, $costEvidence);
                $read = PlatformCommercialUnitEconomics::read(
                    true,
                    $tenant,
                    $planVersion->currency(),
                    $unit,
                    $now,
                );
                $valid = $read['status'] === 'valid';
                $incomplete = $incomplete || !$valid;
                $rows[] = [
                    'tenant_ref' => $tenant,
                    'plan_key' => $plan,
                    'plan_version' => $planVersion->version(),
                    'vertical_key' => $vertical,
                    'status' => $valid ? 'valid' : 'UNKNOWN',
                    'reason' => $valid ? null : 'cost_or_revenue_unknown',
                    'currency' => $valid ? $read['currency'] : null,
                    'source_window' => $valid ? $read['source_window'] : null,
                    'gross_margin_amount' => $valid ? $read['metrics']['gross_margin_amount'] : null,
                    'gross_margin_percent' => $valid ? $read['metrics']['gross_margin_percent'] : null,
                    'contribution_margin_amount' => $valid ? $read['metrics']['contribution_margin_amount'] : null,
                    'contribution_margin_percent' => $valid ? $read['metrics']['contribution_margin_percent'] : null,
                    'provenance' => $valid ? $read['provenance'] : null,
                    'audit_count' => count($audits),
                ];
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($a['tenant_ref'], $b['tenant_ref']));
            return ['status' => $incomplete ? 'UNKNOWN' : 'valid', 'reason' => $incomplete ? 'incomplete_evidence' : null, 'rows' => $rows];
        } catch (Throwable $exception) {
            return ['status' => 'UNKNOWN', 'reason' => $exception instanceof DomainException ? $exception->getMessage() : 'invalid_projection', 'rows' => []];
        }
    }

    /** @param array<string,mixed> $record @param list<string> $expected */
    private static function exactKeys(array $record, array $expected): void
    {
        $keys = array_keys($record);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new DomainException('invalid_shape');
        }
    }

    private static function safeKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $value) === 1;
    }

    private static function synthetic(mixed $value, string $scope): bool
    {
        return is_string($value)
            && preg_match('/^'.preg_quote($scope, '/').':synthetic-[a-z0-9][a-z0-9_-]{0,79}$/D', $value) === 1;
    }
}

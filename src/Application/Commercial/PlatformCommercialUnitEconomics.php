<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

final class PlatformCommercialUnitEconomics
{
    /**
     * @param array<string,mixed> $unitEconomics
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   tenant_ref:string|null,
     *   currency:string|null,
     *   source_window:array{start:string,end:string}|null,
     *   observed_at:string|null,
     *   freshness:array{age_seconds:int,status:'fresh',threshold_seconds:int}|null,
     *   metrics:array{
     *     gross_margin_amount:int,
     *     gross_margin_percent:string,
     *     contribution_margin_amount:int,
     *     contribution_margin_percent:string
     *   }|null,
     *   provenance:array{
     *     revenue:array{kind:string,subscription_ref:string,plan_version_ref:string},
     *     costs:list<array{category:string,source_ref:string}>
     *   }|null
     * }
     */
    public static function read(
        bool $owner,
        string $tenantRef,
        string $currency,
        array $unitEconomics,
        DateTimeImmutable $now,
        int $freshForSeconds = 86400,
    ): array {
        try {
            if (!$owner) {
                throw new DomainException('owner_required');
            }

            $tenantRef = trim($tenantRef);
            $currency = strtoupper(trim($currency));
            if ($tenantRef === '') {
                throw new DomainException('invalid_tenant_ref');
            }
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new DomainException('invalid_currency');
            }
            if ($freshForSeconds < 1 || $freshForSeconds > 31536000) {
                throw new DomainException('invalid_freshness_window');
            }

            self::exactSnapshotKeys($unitEconomics);
            if ($unitEconomics['status'] !== 'valid' || $unitEconomics['reason'] !== null) {
                throw new DomainException('source_unavailable');
            }
            if (!is_string($unitEconomics['tenant_ref']) || $unitEconomics['tenant_ref'] !== $tenantRef) {
                throw new DomainException('tenant_mismatch');
            }
            if (!is_string($unitEconomics['currency']) || $unitEconomics['currency'] !== $currency) {
                throw new DomainException('currency_mismatch');
            }

            $sourceWindow = $unitEconomics['source_window'];
            if (!is_array($sourceWindow)) {
                throw new DomainException('invalid_source_window');
            }
            self::exactKeys($sourceWindow, ['end', 'start'], 'invalid_source_window');
            $windowStart = self::canonicalTime($sourceWindow['start'], 'invalid_source_window');
            $windowEnd = self::canonicalTime($sourceWindow['end'], 'invalid_source_window');
            $observedAt = self::canonicalTime($unitEconomics['observed_at'], 'invalid_observed_at');
            $now = $now->setTimezone(new DateTimeZone('UTC'));

            if ($windowEnd <= $windowStart || $observedAt < $windowEnd || $observedAt > $now) {
                throw new DomainException('incoherent_source_window');
            }

            $ageSeconds = $now->getTimestamp() - $observedAt->getTimestamp();
            if ($ageSeconds > $freshForSeconds) {
                throw new DomainException('stale_evidence');
            }

            $revenueMrr = $unitEconomics['revenue_mrr'];
            $cogs = $unitEconomics['cogs'];
            $supportCost = $unitEconomics['support_cost'];
            $grossAmount = $unitEconomics['gross_margin_amount'];
            $grossPercent = $unitEconomics['gross_margin_percent'];
            $contributionAmount = $unitEconomics['contribution_margin_amount'];
            $contributionPercent = $unitEconomics['contribution_margin_percent'];

            if (
                !is_int($revenueMrr)
                || !is_int($cogs)
                || !is_int($supportCost)
                || !is_int($grossAmount)
                || !is_int($contributionAmount)
                || $revenueMrr < 1
                || $cogs < 0
                || $supportCost < 0
                || !self::isExactPercent($grossPercent)
                || !self::isExactPercent($contributionPercent)
            ) {
                throw new DomainException('incomplete_metrics');
            }

            $provenance = self::provenance($unitEconomics['provenance']);

            return [
                'status' => 'valid',
                'reason' => null,
                'tenant_ref' => $tenantRef,
                'currency' => $currency,
                'source_window' => [
                    'start' => $windowStart->format('Y-m-d\\TH:i:s.u\\Z'),
                    'end' => $windowEnd->format('Y-m-d\\TH:i:s.u\\Z'),
                ],
                'observed_at' => $observedAt->format('Y-m-d\\TH:i:s.u\\Z'),
                'freshness' => [
                    'age_seconds' => $ageSeconds,
                    'status' => 'fresh',
                    'threshold_seconds' => $freshForSeconds,
                ],
                'metrics' => [
                    'gross_margin_amount' => $grossAmount,
                    'gross_margin_percent' => $grossPercent,
                    'contribution_margin_amount' => $contributionAmount,
                    'contribution_margin_percent' => $contributionPercent,
                ],
                'provenance' => $provenance,
            ];
        } catch (Throwable $exception) {
            return self::unavailable(
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'invalid_or_ambiguous_unit_economics_read_model',
            );
        }
    }

    /** @param array<string,mixed> $snapshot */
    private static function exactSnapshotKeys(array $snapshot): void
    {
        self::exactKeys(
            $snapshot,
            [
                'cogs',
                'contribution_margin_amount',
                'contribution_margin_percent',
                'currency',
                'gross_margin_amount',
                'gross_margin_percent',
                'observed_at',
                'provenance',
                'reason',
                'revenue_mrr',
                'source_window',
                'status',
                'support_cost',
                'tenant_ref',
            ],
            'incomplete_source',
        );
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $expected
     */
    private static function exactKeys(array $value, array $expected, string $reason): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException($reason);
        }
    }

    /**
     * @return array{
     *   revenue:array{kind:string,subscription_ref:string,plan_version_ref:string},
     *   costs:list<array{category:string,source_ref:string}>
     * }
     */
    private static function provenance(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new DomainException('incomplete_provenance');
        }
        self::exactKeys($raw, ['costs', 'revenue'], 'incomplete_provenance');

        $revenue = $raw['revenue'];
        $costs = $raw['costs'];
        if (!is_array($revenue) || !is_array($costs) || !array_is_list($costs) || $costs === []) {
            throw new DomainException('incomplete_provenance');
        }
        self::exactKeys(
            $revenue,
            ['kind', 'plan_version_ref', 'subscription_ref'],
            'incomplete_provenance',
        );
        if (
            $revenue['kind'] !== 'subscription_plan_version'
            || !self::nonEmptyString($revenue['subscription_ref'])
            || !self::nonEmptyString($revenue['plan_version_ref'])
        ) {
            throw new DomainException('incomplete_provenance');
        }

        $normalizedCosts = [];
        $seenCategories = [];
        foreach ($costs as $row) {
            if (!is_array($row)) {
                throw new DomainException('incomplete_provenance');
            }
            self::exactKeys($row, ['category', 'source_ref'], 'incomplete_provenance');
            if (!self::nonEmptyString($row['category']) || !self::nonEmptyString($row['source_ref'])) {
                throw new DomainException('incomplete_provenance');
            }
            if (isset($seenCategories[$row['category']])) {
                throw new DomainException('ambiguous_provenance');
            }
            $seenCategories[$row['category']] = true;
            $normalizedCosts[] = [
                'category' => $row['category'],
                'source_ref' => $row['source_ref'],
            ];
        }

        return [
            'revenue' => [
                'kind' => $revenue['kind'],
                'subscription_ref' => $revenue['subscription_ref'],
                'plan_version_ref' => $revenue['plan_version_ref'],
            ],
            'costs' => $normalizedCosts,
        ];
    }

    private static function canonicalTime(mixed $value, string $reason): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1
        ) {
            throw new DomainException($reason);
        }

        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new DomainException($reason);
        }

        $canonical = $parsed->setTimezone(new DateTimeZone('UTC'));
        if ($canonical->format('Y-m-d\\TH:i:s.u\\Z') !== $value) {
            throw new DomainException($reason);
        }

        return $canonical;
    }

    private static function isExactPercent(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^-?\d+(?:\/[1-9]\d*)?$/D', $value) === 1;
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array{
     *   status:'unavailable',
     *   reason:string,
     *   tenant_ref:null,
     *   currency:null,
     *   source_window:null,
     *   observed_at:null,
     *   freshness:null,
     *   metrics:null,
     *   provenance:null
     * }
     */
    private static function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'tenant_ref' => null,
            'currency' => null,
            'source_window' => null,
            'observed_at' => null,
            'freshness' => null,
            'metrics' => null,
            'provenance' => null,
        ];
    }
}

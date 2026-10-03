<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

final class CostAttributionSnapshot
{
    private const CATEGORIES = [
        'ai_compute',
        'infrastructure',
        'integration',
        'messaging',
        'storage',
        'support',
    ];

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   status:'valid'|'unavailable',
     *   reason:string|null,
     *   tenant_ref:string|null,
     *   currency:string|null,
     *   source_window:array{start:string,end:string}|null,
     *   observed_at:string|null,
     *   costs:list<array{category:string,amount:int,currency:string,source_ref:string}>|null,
     *   total_cost:int|null
     * }
     */
    public static function derive(array $input): array
    {
        try {
            self::exactKeys(
                $input,
                ['version', 'tenant_ref', 'window_start', 'window_end', 'observed_at', 'costs'],
                'snapshot',
            );
            if ($input['version'] !== 1) {
                throw new DomainException('unsupported_version');
            }

            $tenantRef = self::opaqueRef($input['tenant_ref'], 'tenant_ref', 128);
            $windowStart = self::canonicalTime($input['window_start']);
            $windowEnd = self::canonicalTime($input['window_end']);
            $observedAt = self::canonicalTime($input['observed_at']);
            if ($windowEnd <= $windowStart) {
                throw new DomainException('invalid_source_window');
            }
            if ($observedAt < $windowEnd) {
                throw new DomainException('observed_before_window_end');
            }
            if (!is_array($input['costs']) || !array_is_list($input['costs'])) {
                throw new DomainException('invalid_cost_list');
            }

            /** @var array<string,array{category:string,amount:int,currency:string,source_ref:string}> $byCategory */
            $byCategory = [];
            $currency = null;
            $totalCost = 0;
            foreach ($input['costs'] as $rawCost) {
                if (!is_array($rawCost)) {
                    throw new DomainException('invalid_cost_entry');
                }
                self::exactKeys(
                    $rawCost,
                    ['category', 'amount', 'currency', 'source_ref'],
                    'cost',
                );

                $category = $rawCost['category'];
                if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
                    throw new DomainException('invalid_cost_category');
                }
                if (isset($byCategory[$category])) {
                    throw new DomainException('duplicate_cost_category');
                }

                $amount = $rawCost['amount'];
                if (is_bool($amount) || !is_int($amount) || $amount < 0) {
                    throw new DomainException('invalid_cost_amount');
                }

                $costCurrency = $rawCost['currency'];
                if (
                    !is_string($costCurrency)
                    || preg_match('/^[A-Z]{3}$/D', $costCurrency) !== 1
                ) {
                    throw new DomainException('invalid_cost_currency');
                }
                if ($currency !== null && $currency !== $costCurrency) {
                    throw new DomainException('mixed_cost_currencies');
                }
                $currency ??= $costCurrency;

                $sourceRef = self::opaqueRef($rawCost['source_ref'], 'source_ref', 160);
                if ($totalCost > PHP_INT_MAX - $amount) {
                    throw new DomainException('cost_overflow');
                }
                $totalCost += $amount;
                $byCategory[$category] = [
                    'category' => $category,
                    'amount' => $amount,
                    'currency' => $costCurrency,
                    'source_ref' => $sourceRef,
                ];
            }

            if (array_keys($byCategory) === [] || count($byCategory) !== count(self::CATEGORIES)) {
                throw new DomainException('incomplete_cost_coverage');
            }
            foreach (self::CATEGORIES as $category) {
                if (!isset($byCategory[$category])) {
                    throw new DomainException('incomplete_cost_coverage');
                }
            }
            if ($currency === null) {
                throw new DomainException('incomplete_cost_coverage');
            }

            $costs = [];
            foreach (self::CATEGORIES as $category) {
                $costs[] = $byCategory[$category];
            }

            return [
                'status' => 'valid',
                'reason' => null,
                'tenant_ref' => $tenantRef,
                'currency' => $currency,
                'source_window' => [
                    'start' => self::formatTime($windowStart),
                    'end' => self::formatTime($windowEnd),
                ],
                'observed_at' => self::formatTime($observedAt),
                'costs' => $costs,
                'total_cost' => $totalCost,
            ];
        } catch (Throwable $exception) {
            return self::unavailable(
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'invalid_or_ambiguous_cost_evidence',
            );
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $scope): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException('invalid_' . $scope . '_shape');
        }
    }

    private static function opaqueRef(mixed $value, string $field, int $maxLength): string
    {
        if (
            !is_string($value)
            || strlen($value) < 3
            || strlen($value) > $maxLength
            || preg_match('/^[a-z][a-z0-9:_-]*$/D', $value) !== 1
        ) {
            throw new DomainException('invalid_' . $field);
        }

        return $value;
    }

    private static function canonicalTime(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1
        ) {
            throw new DomainException('invalid_cost_time');
        }

        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new DomainException('invalid_cost_time');
        }

        $canonical = $parsed->setTimezone(new DateTimeZone('UTC'));
        if (self::formatTime($canonical) !== $value) {
            throw new DomainException('invalid_cost_time');
        }

        return $canonical;
    }

    private static function formatTime(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }

    /**
     * @return array{
     *   status:'unavailable',
     *   reason:string,
     *   tenant_ref:null,
     *   currency:null,
     *   source_window:null,
     *   observed_at:null,
     *   costs:null,
     *   total_cost:null
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
            'costs' => null,
            'total_cost' => null,
        ];
    }
}

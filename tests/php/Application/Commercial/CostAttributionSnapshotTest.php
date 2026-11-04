<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CostAttributionSnapshot;
use PHPUnit\Framework\TestCase;

final class CostAttributionSnapshotTest extends TestCase
{
    public function testDeriveNormalizesSixCostCategoriesAndSumsSyntheticAmounts(): void
    {
        $input = self::completeSnapshot();

        self::assertSame([
            'status' => 'valid',
            'reason' => null,
            'tenant_ref' => 'tenant:synthetic-001',
            'currency' => 'COP',
            'source_window' => [
                'start' => '2026-10-01T00:00:00.000000Z',
                'end' => '2026-10-02T00:00:00.000000Z',
            ],
            'observed_at' => '2026-10-02T00:00:00.000000Z',
            'costs' => [
                self::cost('ai_compute', 100),
                self::cost('infrastructure', 200),
                self::cost('integration', 300),
                self::cost('messaging', 400),
                self::cost('storage', 500),
                self::cost('support', 600),
            ],
            'total_cost' => 2100,
        ], CostAttributionSnapshot::derive($input));

        // A snapshot is a pure, deterministic value: no database or external service.
        self::assertSame(CostAttributionSnapshot::derive($input), CostAttributionSnapshot::derive($input));
    }

    public function testZeroCostCoverageIsValidRatherThanMissingEvidence(): void
    {
        $input = self::completeSnapshot();
        foreach ($input['costs'] as &$cost) {
            $cost['amount'] = 0;
        }
        unset($cost);

        $result = CostAttributionSnapshot::derive($input);
        self::assertSame('valid', $result['status']);
        self::assertSame(0, $result['total_cost']);
        self::assertCount(6, $result['costs']);
    }

    public function testMalformedRootAndTemporalEvidenceFailsClosed(): void
    {
        $valid = self::completeSnapshot();
        $missing = $valid;
        unset($missing['tenant_ref']);

        self::assertUnavailable($missing, 'invalid_snapshot_shape');
        self::assertUnavailable([...$valid, 'customer_email' => 'redacted@example.invalid'], 'invalid_snapshot_shape');
        self::assertUnavailable([...$valid, 'version' => 2], 'unsupported_version');
        self::assertUnavailable([...$valid, 'tenant_ref' => 'Tenant 123'], 'invalid_tenant_ref');
        self::assertUnavailable([...$valid, 'window_start' => '2026-10-01T00:00:00Z'], 'invalid_cost_time');
        self::assertUnavailable([...$valid, 'window_end' => $valid['window_start']], 'invalid_source_window');
        self::assertUnavailable([...$valid, 'observed_at' => $valid['window_start']], 'observed_before_window_end');
        self::assertUnavailable([...$valid, 'costs' => ['first' => self::cost('storage', 500)]], 'invalid_cost_list');
        self::assertUnavailable([...$valid, 'costs' => [42]], 'invalid_cost_entry');
    }

    public function testIncompleteDuplicatedOrAmbiguousCostsAreNeverReportedAsValid(): void
    {
        $valid = self::completeSnapshot();
        self::assertUnavailable([...$valid, 'costs' => []], 'incomplete_cost_coverage');
        self::assertUnavailable([...$valid, 'costs' => array_slice($valid['costs'], 0, 5)], 'incomplete_cost_coverage');

        self::assertUnavailable(self::withCost($valid, 1, ['category' => 'storage']), 'duplicate_cost_category');
        self::assertUnavailable(self::withCost($valid, 1, ['category' => 'unknown']), 'invalid_cost_category');
        self::assertUnavailable(self::withCost($valid, 1, ['amount' => -1]), 'invalid_cost_amount');
        self::assertUnavailable(self::withCost($valid, 1, ['amount' => true]), 'invalid_cost_amount');
        self::assertUnavailable(self::withCost($valid, 1, ['amount' => '200']), 'invalid_cost_amount');
        self::assertUnavailable(self::withCost($valid, 1, ['currency' => 'USD']), 'mixed_cost_currencies');
        self::assertUnavailable(self::withCost($valid, 1, ['currency' => 'cop']), 'invalid_cost_currency');
        self::assertUnavailable(self::withCost($valid, 1, ['source_ref' => 'bad reference']), 'invalid_source_ref');
        self::assertUnavailable(self::withCost($valid, 1, ['freeform_note' => 'do not accept']), 'invalid_cost_shape');
        self::assertUnavailable(self::withCost($valid, 0, ['amount' => PHP_INT_MAX]), 'cost_overflow');
    }

    /**
     * @return array<string, mixed>
     */
    private static function completeSnapshot(): array
    {
        return [
            'version' => 1,
            'tenant_ref' => 'tenant:synthetic-001',
            'window_start' => '2026-10-01T00:00:00.000000Z',
            'window_end' => '2026-10-02T00:00:00.000000Z',
            'observed_at' => '2026-10-02T00:00:00.000000Z',
            // Deliberately unsorted: derive must normalize to its canonical order.
            'costs' => [
                self::cost('storage', 500),
                self::cost('ai_compute', 100),
                self::cost('support', 600),
                self::cost('infrastructure', 200),
                self::cost('messaging', 400),
                self::cost('integration', 300),
            ],
        ];
    }

    /**
     * @return array{category:string,amount:int,currency:string,source_ref:string}
     */
    private static function cost(string $category, int $amount): array
    {
        return [
            'category' => $category,
            'amount' => $amount,
            'currency' => 'COP',
            'source_ref' => 'source:synthetic-001',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private static function withCost(array $input, int $index, array $change): array
    {
        $input['costs'][$index] = [...$input['costs'][$index], ...$change];
        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function assertUnavailable(array $input, string $reason): void
    {
        self::assertSame([
            'status' => 'unavailable',
            'reason' => $reason,
            'tenant_ref' => null,
            'currency' => null,
            'source_window' => null,
            'observed_at' => null,
            'costs' => null,
            'total_cost' => null,
        ], CostAttributionSnapshot::derive($input), 'Invalid evidence must fail closed: '.$reason);
    }
}

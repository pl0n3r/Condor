<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use InvalidArgumentException;

/** Offline and synthetic. Never sends or stores purchase orders. */
final class QuotationPreview
{
    private const MAX_LINES = 20;
    private const MAX_QUANTITY_MILLI = 1_000_000;
    private const MAX_UNIT_PRICE_CENTS = 100_000_000;
    private const MAX_TOTAL_COP = 100_000_000;

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function preview(array $request): array
    {
        self::exactKeys($request, ['tenant_ref', 'legal_entity_ref', 'site_ref', 'currency', 'lines', 'mode']);
        foreach (['tenant_ref','legal_entity_ref','site_ref'] as $key) {
            self::fixture($request[$key] ?? null, $key);
        }
        if (($request['currency'] ?? null) !== 'COP' || ($request['mode'] ?? null) !== 'preview_only') {
            throw new InvalidArgumentException('PREVIEW_ONLY_COP');
        }
        $items = $request['lines'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > self::MAX_LINES) {
            throw new InvalidArgumentException('INVALID_LINES');
        }
        $output = [];
        $seen = [];
        $total = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('INVALID_LINE');
            }
            self::exactKeys($item, ['item_ref', 'tenant_ref', 'legal_entity_ref', 'site_ref', 'quantity', 'unit_price_cop']);
            self::fixture($item['item_ref'] ?? null, 'item_ref');
            foreach (['tenant_ref','legal_entity_ref','site_ref'] as $key) {
                self::fixture($item[$key] ?? null, $key);
                if ($item[$key] !== $request[$key]) {
                    throw new InvalidArgumentException('SCOPE_MISMATCH');
                }
            }
            if (isset($seen[$item['item_ref']])) {
                throw new InvalidArgumentException('DUPLICATE_ITEM');
            }
            $seen[$item['item_ref']] = true;
            $qtyMilli = self::decimal($item['quantity'] ?? null, 3, self::MAX_QUANTITY_MILLI);
            $unitCents = self::decimal($item['unit_price_cop'] ?? null, 2, self::MAX_UNIT_PRICE_CENTS);
            // Decimal quantities x decimal prices, rounded half-up to whole COP per line.
            $lineCop = intdiv($qtyMilli * $unitCents + 50_000, 100_000);
            if ($lineCop > self::MAX_TOTAL_COP - $total) {
                throw new InvalidArgumentException('TOTAL_LIMIT');
            }
            $total += $lineCop;
            $output[] = ['item_ref' => $item['item_ref'], 'line_total_cop' => $lineCop];
        }
        return [
            'status' => 'SYNTHETIC_PREVIEW', 'currency' => 'COP',
            'mode' => 'preview_only', 'line_count' => count($output),
            'total_cop' => $total, 'lines' => $output,
            'can_order' => false, 'can_pay' => false,
            'can_approve' => false, 'can_dispatch' => false,
            'persisted' => false,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
    }

    private static function fixture(mixed $value, string $key): void
    {
        if (!is_string($value) || preg_match('~^fixture:[a-z][a-z0-9-]*/[a-z0-9][a-z0-9-]{0,63}$~D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE_' . strtoupper($key));
        }
    }

    private static function decimal(mixed $value, int $scale, int $max): int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,3}))?$/D', $value, $m) !== 1) {
            throw new InvalidArgumentException('INVALID_AMOUNT');
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('INVALID_PRECISION');
        }
        $units = (int)$m[1] * (10 ** $scale) + (int)str_pad($fraction, $scale, '0');
        if ($units <= 0 || $units > $max) {
            throw new InvalidArgumentException('AMOUNT_LIMIT');
        }
        return $units;
    }
}

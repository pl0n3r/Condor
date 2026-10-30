<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use InvalidArgumentException;

/**
 * Pure, synthetic M09/S03 purchase-request projection. No persistence, network,
 * approvals, purchase orders, pricing, tax decisions, or external side effects.
 */
final class PurchaseRequestPreview
{
    private const MAX_LINES = 20;
    private const MAX_QUANTITY_MILLI = 1_000_000;

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function preview(array $request): array
    {
        $required = ['tenant_ref', 'legal_entity_ref', 'site_ref', 'currency', 'mode', 'lines'];
        $optional = ['quotation'];
        self::keys($request, $required, $optional);
        foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $field) {
            self::fixture($request[$field], $field);
        }
        if ($request['currency'] !== 'COP' || $request['mode'] !== 'preview_only') {
            throw new InvalidArgumentException('PREVIEW_ONLY_COP');
        }

        $sourceLines = $request['lines'];
        if (!is_array($sourceLines) || !array_is_list($sourceLines)
            || count($sourceLines) < 1 || count($sourceLines) > self::MAX_LINES) {
            throw new InvalidArgumentException('INVALID_LINES');
        }

        $lines = [];
        $seen = [];
        foreach ($sourceLines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('INVALID_LINE');
            }
            self::keys($line, ['item_ref', 'tenant_ref', 'legal_entity_ref', 'site_ref', 'quantity']);
            self::fixture($line['item_ref'], 'item_ref');
            self::sameScope($line, $request);
            $item = $line['item_ref'];
            if (isset($seen[$item])) {
                throw new InvalidArgumentException('DUPLICATE_ITEM');
            }
            $seen[$item] = true;
            $quantity = self::quantity($line['quantity']);
            $lines[] = ['item_ref' => $item, 'quantity' => $quantity];
        }

        $quotationRef = null;
        if (array_key_exists('quotation', $request)) {
            $quotation = $request['quotation'];
            if (!is_array($quotation)) {
                throw new InvalidArgumentException('INVALID_QUOTATION');
            }
            self::keys($quotation, ['quotation_ref', 'tenant_ref', 'legal_entity_ref', 'site_ref']);
            self::fixture($quotation['quotation_ref'], 'quotation_ref');
            self::sameScope($quotation, $request);
            $quotationRef = $quotation['quotation_ref'];
        }

        // Deliberately explicit output allowlist; no incidental input fields leak.
        return [
            'tenant_ref' => $request['tenant_ref'],
            'legal_entity_ref' => $request['legal_entity_ref'],
            'site_ref' => $request['site_ref'],
            'currency' => 'COP',
            'quotation_ref' => $quotationRef,
            'status' => 'pending',
            'mode' => 'preview_only',
            'line_count' => count($lines),
            'lines' => $lines,
            'can_order' => false,
            'can_approve' => false,
            'can_pay' => false,
            'can_dispatch' => false,
            'persisted' => false,
            'notified' => false,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $scope
     */
    private static function sameScope(array $data, array $scope): void
    {
        foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $key) {
            self::fixture($data[$key], $key);
            if ($data[$key] !== $scope[$key]) {
                throw new InvalidArgumentException('SCOPE_MISMATCH');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function keys(array $data, array $required, array $optional = []): void
    {
        $actual = array_keys($data);
        if (array_diff($required, $actual) || array_diff($actual, array_merge($required, $optional))) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
    }

    private static function fixture(mixed $value, string $field): void
    {
        $kinds = [
            'tenant_ref' => 'tenant',
            'legal_entity_ref' => 'legal-entity',
            'site_ref' => 'site',
            'item_ref' => 'item',
            'quotation_ref' => 'quotation',
        ];
        if (!isset($kinds[$field]) || !is_string($value)
            || preg_match('~^fixture:' . $kinds[$field] . '/[a-z0-9][a-z0-9-]{0,63}$~D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE_' . strtoupper($field));
        }
    }

    private static function quantity(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,3}))?$/D', $value, $m) !== 1) {
            throw new InvalidArgumentException('INVALID_QUANTITY');
        }
        $scaled = ((int) $m[1] * 1_000) + (int) str_pad($m[2] ?? '', 3, '0');
        if ($scaled < 1 || $scaled > self::MAX_QUANTITY_MILLI) {
            throw new InvalidArgumentException('QUANTITY_LIMIT');
        }
        return sprintf('%d.%03d', intdiv($scaled, 1_000), $scaled % 1_000);
    }
}

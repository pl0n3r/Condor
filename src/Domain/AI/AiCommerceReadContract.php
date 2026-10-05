<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final readonly class AiCommerceReadContract
{
    private const CURRENCIES = ['COP', 'EUR', 'USD'];
    private const CATALOG_STATUSES = ['available', 'unavailable'];
    private const INVENTORY_STATUSES = ['in_stock', 'out_of_stock', 'unavailable'];

    public function __construct(private AiToolPolicy $policy)
    {
    }

    public function catalogDescriptor(): AiToolDescriptor
    {
        return AiToolDescriptor::fromArray($this->policy, [
            'tool' => 'catalog.read',
            'risk' => AiToolPolicy::READ_ONLY,
            'input_names' => ['price_list_ref', 'product_ref'],
            'required_inputs' => ['product_ref'],
            'output_names' => ['amount', 'currency', 'product_ref', 'status'],
            'required_outputs' => ['amount', 'currency', 'product_ref', 'status'],
        ]);
    }

    public function inventoryDescriptor(): AiToolDescriptor
    {
        return AiToolDescriptor::fromArray($this->policy, [
            'tool' => 'inventory.read',
            'risk' => AiToolPolicy::READ_ONLY,
            'input_names' => ['location_ref', 'product_ref'],
            'required_inputs' => ['product_ref'],
            'output_names' => ['location_ref', 'product_ref', 'quantity', 'status'],
            'required_outputs' => ['location_ref', 'product_ref', 'quantity', 'status'],
        ]);
    }

    /** @param array<string, mixed> $inputs */
    public function validateCatalogInputs(array $inputs): void
    {
        $this->catalogDescriptor()->validateInputs($inputs);
        self::ref($inputs['product_ref'], 'product', 'product_ref');
        if (($inputs['price_list_ref'] ?? null) !== null) {
            self::ref($inputs['price_list_ref'], 'price_list', 'price_list_ref');
        }
    }

    /** @param array<string, mixed> $outputs */
    public function validateCatalogOutputs(array $outputs): void
    {
        $this->catalogDescriptor()->validateOutputs($outputs);
        self::ref($outputs['product_ref'], 'product', 'product_ref');
        $status = self::status($outputs['status'], self::CATALOG_STATUSES, 'catalog.status');

        if ($status === 'unavailable') {
            if ($outputs['currency'] !== null || $outputs['amount'] !== null) {
                throw new DomainException('Producto no disponible no puede publicar precio.');
            }
            return;
        }

        self::status($outputs['currency'], self::CURRENCIES, 'currency');
        self::decimal($outputs['amount'], 'amount', 999_999_999.99, 2);
    }

    /** @param array<string, mixed> $inputs */
    public function validateInventoryInputs(array $inputs): void
    {
        $this->inventoryDescriptor()->validateInputs($inputs);
        self::ref($inputs['product_ref'], 'product', 'product_ref');
        if (($inputs['location_ref'] ?? null) !== null) {
            self::ref($inputs['location_ref'], 'location', 'location_ref');
        }
    }

    /** @param array<string, mixed> $outputs */
    public function validateInventoryOutputs(array $outputs): void
    {
        $this->inventoryDescriptor()->validateOutputs($outputs);
        self::ref($outputs['product_ref'], 'product', 'product_ref');
        if ($outputs['location_ref'] !== null) {
            self::ref($outputs['location_ref'], 'location', 'location_ref');
        }

        $quantity = self::decimal($outputs['quantity'], 'quantity', 999_999_999.999, 3);
        $status = self::status($outputs['status'], self::INVENTORY_STATUSES, 'inventory.status');
        if (($status === 'in_stock') !== ($quantity > 0.0)) {
            throw new DomainException('quantity/status de inventario incoherentes.');
        }
    }

    private static function ref(mixed $value, string $prefix, string $field): void
    {
        $pattern = '/^' . preg_quote($prefix, '/') . ':[a-z0-9][a-z0-9_-]{0,63}$/D';
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new DomainException($field . ' no es referencia canónica.');
        }
    }

    /** @param list<string> $allowlist */
    private static function status(mixed $value, array $allowlist, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowlist, true)) {
            throw new DomainException($field . ' fuera de la allowlist.');
        }
        return $value;
    }

    private static function decimal(mixed $value, string $field, float $max, int $scale): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new DomainException($field . ' debe ser numérico.');
        }
        $number = (float) $value;
        $factor = 10 ** $scale;
        if (
            !is_finite($number)
            || $number < 0.0
            || $number > $max
            || abs(($number * $factor) - round($number * $factor)) > 0.000001
        ) {
            throw new DomainException($field . ' fuera de rango o precisión.');
        }
        return $number;
    }
}

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
        return AiToolDescriptor::fromArray(
            $this->policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['price_list_ref', 'product_ref'],
                'required_inputs' => ['product_ref'],
                'output_names' => ['amount', 'currency', 'product_ref', 'status'],
                'required_outputs' => ['amount', 'currency', 'product_ref', 'status'],
            ],
        );
    }

    public function inventoryDescriptor(): AiToolDescriptor
    {
        return AiToolDescriptor::fromArray(
            $this->policy,
            [
                'tool' => 'inventory.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['location_ref', 'product_ref'],
                'required_inputs' => ['product_ref'],
                'output_names' => ['location_ref', 'product_ref', 'quantity', 'status'],
                'required_outputs' => ['location_ref', 'product_ref', 'quantity', 'status'],
            ],
        );
    }

    /** @param array<string, mixed> $inputs */
    public function validateCatalogInputs(array $inputs): void
    {
        $this->catalogDescriptor()->validateInputs($inputs);
        self::reference($inputs['product_ref'], 'product', 'product_ref');
        if (array_key_exists('price_list_ref', $inputs) && $inputs['price_list_ref'] !== null) {
            self::reference($inputs['price_list_ref'], 'price_list', 'price_list_ref');
        }
    }

    /** @param array<string, mixed> $outputs */
    public function validateCatalogOutputs(array $outputs): void
    {
        $this->catalogDescriptor()->validateOutputs($outputs);
        self::reference($outputs['product_ref'], 'product', 'product_ref');
        $status = self::status($outputs['status'], self::CATALOG_STATUSES, 'catalog.status');

        if ($status === 'unavailable') {
            if ($outputs['currency'] !== null || $outputs['amount'] !== null) {
                throw new DomainException('Producto no disponible no puede publicar precio.');
            }

            return;
        }

        self::currency($outputs['currency']);
        self::decimal($outputs['amount'], 'amount', 999_999_999.99, 2);
    }

    /** @param array<string, mixed> $inputs */
    public function validateInventoryInputs(array $inputs): void
    {
        $this->inventoryDescriptor()->validateInputs($inputs);
        self::reference($inputs['product_ref'], 'product', 'product_ref');
        if (array_key_exists('location_ref', $inputs) && $inputs['location_ref'] !== null) {
            self::reference($inputs['location_ref'], 'location', 'location_ref');
        }
    }

    /** @param array<string, mixed> $outputs */
    public function validateInventoryOutputs(array $outputs): void
    {
        $this->inventoryDescriptor()->validateOutputs($outputs);
        self::reference($outputs['product_ref'], 'product', 'product_ref');
        if ($outputs['location_ref'] !== null) {
            self::reference($outputs['location_ref'], 'location', 'location_ref');
        }

        $quantity = self::decimal($outputs['quantity'], 'quantity', 999_999_999.999, 3);
        $status = self::status(
            $outputs['status'],
            self::INVENTORY_STATUSES,
            'inventory.status',
        );

        if ($status === 'in_stock' && $quantity <= 0.0) {
            throw new DomainException('Stock disponible requiere cantidad positiva.');
        }
        if ($status !== 'in_stock' && $quantity !== 0.0) {
            throw new DomainException('Stock no disponible requiere cantidad cero.');
        }
    }

    private static function reference(mixed $value, string $prefix, string $field): string
    {
        if (!is_string($value)) {
            throw new DomainException($field . ' debe ser referencia canónica.');
        }
        $pattern = '/^' . preg_quote($prefix, '/') . ':[a-z0-9][a-z0-9_-]{0,63}$/D';
        if (preg_match($pattern, $value) !== 1) {
            throw new DomainException($field . ' no es referencia canónica.');
        }

        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::CURRENCIES, true)) {
            throw new DomainException('currency fuera de la allowlist.');
        }

        return $value;
    }

    /**
     * @param list<string> $allowlist
     */
    private static function status(mixed $value, array $allowlist, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowlist, true)) {
            throw new DomainException($field . ' fuera de la allowlist.');
        }

        return $value;
    }

    private static function decimal(
        mixed $value,
        string $field,
        float $maximum,
        int $scale,
    ): float {
        if (!is_int($value) && !is_float($value)) {
            throw new DomainException($field . ' debe ser numérico.');
        }

        $number = (float) $value;
        if (!is_finite($number) || $number < 0.0 || $number > $maximum) {
            throw new DomainException($field . ' fuera de rango.');
        }

        $factor = 10 ** $scale;
        if (abs(($number * $factor) - round($number * $factor)) > 0.000001) {
            throw new DomainException($field . ' excede la precisión permitida.');
        }

        return $number;
    }
}

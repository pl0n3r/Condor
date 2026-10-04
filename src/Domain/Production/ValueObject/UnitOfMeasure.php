<?php

declare(strict_types=1);

namespace App\Domain\Production\ValueObject;

use DomainException;

final readonly class UnitOfMeasure
{
    private const SCALE = 1_000_000;
    private const MAX_WHOLE = '9000000000000';

    /** @var array<string,array{magnitude:string,factor:int}> */
    private const DEFINITIONS = [
        'unit' => ['magnitude' => 'count', 'factor' => 1],
        'kg' => ['magnitude' => 'mass', 'factor' => 1000],
        'g' => ['magnitude' => 'mass', 'factor' => 1],
        'l' => ['magnitude' => 'volume', 'factor' => 1000],
        'ml' => ['magnitude' => 'volume', 'factor' => 1],
        'm' => ['magnitude' => 'length', 'factor' => 1],
    ];

    private string $key;

    public function __construct(string $key)
    {
        $key = strtolower(trim($key));
        if (!array_key_exists($key, self::DEFINITIONS)) {
            throw new DomainException('La unidad de medida no es canónica.');
        }

        $this->key = $key;
    }

    public static function from(string $key): self
    {
        return new self($key);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function magnitude(): string
    {
        return self::DEFINITIONS[$this->key]['magnitude'];
    }

    public function normalizeQuantity(string $quantity): string
    {
        return self::formatMicros(self::parseMicros($quantity));
    }

    public function convert(string $quantity, self $target): string
    {
        if ($this->magnitude() !== $target->magnitude()) {
            throw new DomainException(
                'No se pueden convertir unidades de magnitudes incompatibles.',
            );
        }

        $micros = self::parseMicros($quantity);
        $sourceFactor = self::DEFINITIONS[$this->key]['factor'];
        $targetFactor = self::DEFINITIONS[$target->key]['factor'];

        if ($micros > intdiv(PHP_INT_MAX, $sourceFactor)) {
            throw new DomainException(
                'La cantidad excede el rango seguro de conversión.',
            );
        }

        $scaled = $micros * $sourceFactor;
        if ($scaled % $targetFactor !== 0) {
            throw new DomainException(
                'La conversión excede la precisión fija de seis decimales.',
            );
        }

        return self::formatMicros(intdiv($scaled, $targetFactor));
    }

    private static function parseMicros(string $quantity): int
    {
        $quantity = trim($quantity);
        if (
            preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/D', $quantity, $matches) !== 1
        ) {
            throw new DomainException(
                'La cantidad debe ser decimal no negativa con máximo seis decimales.',
            );
        }

        $whole = $matches[1];
        if (
            strlen($whole) > strlen(self::MAX_WHOLE)
            || (
                strlen($whole) === strlen(self::MAX_WHOLE)
                && strcmp($whole, self::MAX_WHOLE) > 0
            )
        ) {
            throw new DomainException('La cantidad excede el rango permitido.');
        }

        $fraction = str_pad($matches[2] ?? '', 6, '0');
        $micros = ((int) $whole * self::SCALE) + (int) $fraction;
        if ($micros < 0) {
            throw new DomainException('La cantidad excede el rango permitido.');
        }

        return $micros;
    }

    private static function formatMicros(int $micros): string
    {
        $whole = intdiv($micros, self::SCALE);
        $fraction = $micros % self::SCALE;
        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole.'.'.rtrim(str_pad((string) $fraction, 6, '0', STR_PAD_LEFT), '0');
    }
}

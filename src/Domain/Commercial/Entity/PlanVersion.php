<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DomainException;

final class PlanVersion
{
    private string $id;
    private string $currency;
    /** @var array<string, bool|int|string|null> */
    private array $limits;
    /** @var array<string, Vertical> */
    private array $verticals = [];

    /**
     * @param array<string, bool|int|string|null> $limits
     */
    public function __construct(
        private readonly Plan $plan,
        private readonly int $version,
        private readonly ?int $monthlyAmount,
        private readonly ?int $annualAmount,
        private readonly bool $quoteRequired,
        array $limits,
        private readonly DateTimeImmutable $effectiveFrom,
        private readonly ?DateTimeImmutable $effectiveUntil = null,
        string $currency = 'COP',
    ) {
        if ($version < 1) {
            throw new DomainException('La versión comercial debe ser positiva.');
        }

        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('Moneda comercial inválida.');
        }

        if ($quoteRequired) {
            if ($monthlyAmount !== null || $annualAmount !== null) {
                throw new DomainException('Una versión cotizable no define precio.');
            }
        } elseif (
            $monthlyAmount === null
            || $monthlyAmount < 1
            || ($annualAmount !== null && $annualAmount < 1)
        ) {
            throw new DomainException('El precio mensual debe ser positivo.');
        }

        if ($effectiveUntil !== null && $effectiveUntil <= $effectiveFrom) {
            throw new DomainException('Vigencia comercial inválida.');
        }

        $this->id = UlidFactory::new();
        $this->currency = $currency;
        $this->limits = self::normalizeLimits($limits);
    }

    public function id(): string { return $this->id; }
    public function plan(): Plan { return $this->plan; }
    public function version(): int { return $this->version; }
    public function currency(): string { return $this->currency; }
    public function monthlyAmount(): ?int { return $this->monthlyAmount; }
    public function annualAmount(): ?int { return $this->annualAmount; }
    public function quoteRequired(): bool { return $this->quoteRequired; }
    /** @return array<string, bool|int|string|null> */
    public function limits(): array { return $this->limits; }
    public function effectiveFrom(): DateTimeImmutable { return $this->effectiveFrom; }
    public function effectiveUntil(): ?DateTimeImmutable { return $this->effectiveUntil; }

    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        return $at >= $this->effectiveFrom
            && ($this->effectiveUntil === null || $at < $this->effectiveUntil);
    }

    public function addVertical(Vertical $vertical): void
    {
        $this->verticals[$vertical->key()] = $vertical;
    }

    /** @return list<Vertical> */
    public function verticals(): array
    {
        return array_values($this->verticals);
    }

    /**
     * @param array<string, bool|int|string|null> $limits
     * @return array<string, bool|int|string|null>
     */
    private static function normalizeLimits(array $limits): array
    {
        if (count($limits) > 50 || ($limits !== [] && array_is_list($limits))) {
            throw new DomainException('Mapa de límites comerciales inválido.');
        }

        foreach ($limits as $key => $value) {
            if (
                !is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $key) !== 1
                || (is_int($value) && $value < 0)
                || (is_string($value) && mb_strlen($value, 'UTF-8') > 120)
            ) {
                throw new DomainException('Límite comercial inválido.');
            }
        }
        ksort($limits);

        return $limits;
    }
}

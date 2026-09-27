<?php

declare(strict_types=1);

namespace App\Domain\CommercialCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_addon')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_addon_key', columns: ['catalog_key'])]
final class AddOn extends CommercialCatalogRecord
{
    public const PRICING_FIXED = 'fixed';
    public const PRICING_FROM = 'from';
    public const PRICING_QUOTE = 'quote';

    #[ORM\Column(name: 'pricing_mode', type: 'string', length: 16)]
    private string $pricingMode;

    #[ORM\Column(name: 'monthly_price_cop', type: 'bigint', nullable: true)]
    private ?int $monthlyPriceCop;

    public function __construct(
        string $key,
        string $name,
        string $pricingMode,
        ?int $monthlyPriceCop,
    ) {
        parent::__construct($key, $name);

        $pricingMode = strtolower(trim($pricingMode));
        if (!in_array(
            $pricingMode,
            [self::PRICING_FIXED, self::PRICING_FROM, self::PRICING_QUOTE],
            true,
        )) {
            throw new DomainException('El modo de precio del add-on no es válido.');
        }
        if ($monthlyPriceCop !== null && $monthlyPriceCop < 0) {
            throw new DomainException('El precio mensual del add-on no puede ser negativo.');
        }
        if ($pricingMode === self::PRICING_FIXED && $monthlyPriceCop === null) {
            throw new DomainException('Un add-on de precio fijo requiere precio mensual.');
        }

        $this->pricingMode = $pricingMode;
        $this->monthlyPriceCop = $monthlyPriceCop;
    }

    public function pricingMode(): string
    {
        return $this->pricingMode;
    }

    public function monthlyPriceCop(): ?int
    {
        return $this->monthlyPriceCop;
    }
}

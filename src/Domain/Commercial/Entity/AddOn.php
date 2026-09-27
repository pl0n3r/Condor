<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_addon')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_addon_key', columns: ['catalog_key'])]
final class AddOn extends CommercialIdentity
{
    #[ORM\Column(name: 'monthly_amount', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $monthlyAmount;

    #[ORM\Column(name: 'quote_required', type: 'boolean')]
    private bool $quoteRequired;

    public function __construct(
        string $key,
        string $name,
        ?int $monthlyAmount,
        bool $quoteRequired = false,
    ) {
        parent::__construct($key, $name);
        if ($quoteRequired) {
            if ($monthlyAmount !== null) {
                throw new DomainException('Un add-on cotizable no define precio cerrado.');
            }
        } elseif ($monthlyAmount === null || $monthlyAmount < 1) {
            throw new DomainException('El precio mensual del add-on debe ser positivo.');
        }

        $this->monthlyAmount = $monthlyAmount;
        $this->quoteRequired = $quoteRequired;
    }

    public function monthlyAmount(): ?int { return $this->monthlyAmount; }
    public function quoteRequired(): bool { return $this->quoteRequired; }
}

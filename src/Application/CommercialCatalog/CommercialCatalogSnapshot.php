<?php

declare(strict_types=1);

namespace App\Application\CommercialCatalog;

use App\Domain\CommercialCatalog\Entity\AddOn;
use App\Domain\CommercialCatalog\Entity\Capability;
use App\Domain\CommercialCatalog\Entity\PlanVersion;
use App\Domain\CommercialCatalog\Entity\Vertical;
use DateTimeImmutable;

final class CommercialCatalogSnapshot
{
    /**
     * @param list<PlanVersion> $planVersions
     * @param list<Vertical> $verticals
     * @param list<Capability> $capabilities
     * @param list<AddOn> $addOns
     */
    public function __construct(
        private readonly DateTimeImmutable $effectiveAt,
        private readonly array $planVersions,
        private readonly array $verticals,
        private readonly array $capabilities,
        private readonly array $addOns,
    ) {
    }

    public function effectiveAt(): DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    /** @return list<PlanVersion> */
    public function planVersions(): array
    {
        return $this->planVersions;
    }

    /** @return list<Vertical> */
    public function verticals(): array
    {
        return $this->verticals;
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /** @return list<AddOn> */
    public function addOns(): array
    {
        return $this->addOns;
    }
}

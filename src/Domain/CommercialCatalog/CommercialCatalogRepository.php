<?php

declare(strict_types=1);

namespace App\Domain\CommercialCatalog;

use App\Domain\CommercialCatalog\Entity\AddOn;
use App\Domain\CommercialCatalog\Entity\Capability;
use App\Domain\CommercialCatalog\Entity\PlanVersion;
use App\Domain\CommercialCatalog\Entity\Vertical;
use DateTimeImmutable;

interface CommercialCatalogRepository
{
    /** @return list<PlanVersion> */
    public function effectivePlanVersions(DateTimeImmutable $at): array;

    /** @return list<Vertical> */
    public function activeVerticals(): array;

    /** @return list<Capability> */
    public function activeCapabilities(): array;

    /** @return list<AddOn> */
    public function activeAddOns(): array;
}

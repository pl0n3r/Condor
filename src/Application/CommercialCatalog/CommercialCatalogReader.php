<?php

declare(strict_types=1);

namespace App\Application\CommercialCatalog;

use App\Domain\CommercialCatalog\CommercialCatalogRepository;
use DateTimeImmutable;
use DateTimeZone;

final class CommercialCatalogReader
{
    public function __construct(
        private readonly CommercialCatalogRepository $repository,
    ) {
    }

    public function current(?DateTimeImmutable $at = null): CommercialCatalogSnapshot
    {
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new CommercialCatalogSnapshot(
            $at,
            $this->repository->effectivePlanVersions($at),
            $this->repository->activeVerticals(),
            $this->repository->activeCapabilities(),
            $this->repository->activeAddOns(),
        );
    }
}

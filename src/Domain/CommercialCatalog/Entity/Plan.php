<?php

declare(strict_types=1);

namespace App\Domain\CommercialCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_plan')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_plan_key', columns: ['catalog_key'])]
final class Plan extends CommercialCatalogRecord
{
}

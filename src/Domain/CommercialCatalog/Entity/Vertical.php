<?php

declare(strict_types=1);

namespace App\Domain\CommercialCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_vertical')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_vertical_key', columns: ['catalog_key'])]
final class Vertical extends CommercialCatalogRecord
{
}

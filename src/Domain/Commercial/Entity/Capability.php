<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_capability')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_capability_key', columns: ['catalog_key'])]
final class Capability extends CommercialIdentity
{
}

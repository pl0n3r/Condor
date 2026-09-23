<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class CommercialItem extends CommercialRecord
{
    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    protected function __construct(Tenant $tenant)
    {
        parent::__construct($tenant);
    }

    final public function isActive(): bool
    {
        return $this->active;
    }

    final public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch();
    }
}

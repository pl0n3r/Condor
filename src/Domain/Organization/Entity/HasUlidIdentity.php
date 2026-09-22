<?php

declare(strict_types=1);

namespace App\Domain\Organization\Entity;

use App\Shared\Id\UlidFactory;
use Doctrine\ORM\Mapping as ORM;

trait HasUlidIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    private function initializeUlidIdentity(): void
    {
        $this->id = UlidFactory::new();
    }

    public function id(): string
    {
        return $this->id;
    }
}

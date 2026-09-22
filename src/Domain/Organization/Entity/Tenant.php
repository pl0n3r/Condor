<?php

declare(strict_types=1);

namespace App\Domain\Organization\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_tenant')]
class Tenant
{
    use HasUlidIdentity;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, string $slug)
    {
        $this->initializeUlidIdentity();
        $this->name = trim($name);
        $this->slug = strtolower(trim($slug));
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

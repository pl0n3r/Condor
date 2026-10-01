<?php

declare(strict_types=1);

namespace App\Domain\Cms\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_cms_theme')]
#[ORM\UniqueConstraint(name: 'uniq_cms_theme_tenant_key', columns: ['tenant_id', 'theme_key'])]
final class CmsTheme
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'theme_key', type: 'string', length: 64)]
    private string $key;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, string $key, string $name)
    {
        $normalizedKey = strtolower(trim($key));
        if ($normalizedKey === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $normalizedKey) !== 1) {
            throw new \DomainException('La clave del tema CMS no es válida.');
        }

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            throw new \DomainException('El nombre del tema CMS es obligatorio.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->key = $normalizedKey;
        $this->name = $normalizedName;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }
}

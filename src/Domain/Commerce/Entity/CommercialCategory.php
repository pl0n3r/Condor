<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_category')]
#[ORM\UniqueConstraint(name: 'uniq_commercial_category_tenant_slug', columns: ['tenant_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_commercial_category_tenant_id', columns: ['tenant_id', 'id'])]
class CommercialCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(name: 'preferred_price_list_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?PriceList $preferredPriceList = null;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant, string $name, string $slug)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function tenant(): Tenant { return $this->tenant; }
    public function name(): string { return $this->name; }
    public function slug(): string { return $this->slug; }
    public function isActive(): bool { return $this->active; }
    public function preferredPriceList(): ?PriceList { return $this->preferredPriceList; }

    public function assignPreferredPriceList(?PriceList $priceList): void
    {
        if ($priceList !== null && $priceList->tenant()->id() !== $this->tenant->id()) {
            throw new DomainException('La lista preferida debe pertenecer al mismo tenant de la categoría comercial.');
        }

        $this->preferredPriceList = $priceList;
        $this->touch();
    }

    public function update(string $name, string $slug): void
    {
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->touch();
    }

    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException('El nombre de la categoría comercial es obligatorio y admite máximo 160 caracteres.');
        }

        return $name;
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || mb_strlen($slug, 'UTF-8') > 120 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new DomainException('El slug de la categoría comercial debe usar letras minúsculas, números y guiones.');
        }

        return $slug;
    }
}

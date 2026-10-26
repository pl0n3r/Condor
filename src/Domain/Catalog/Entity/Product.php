<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_product')]
#[ORM\UniqueConstraint(name: 'uniq_product_tenant_slug', columns: ['tenant_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_product_tenant_id', columns: ['tenant_id', 'id'])]
class Product extends CatalogItem
{
    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?ProductCategory $category = null;

    #[ORM\Column(name: 'allow_backorder', type: 'boolean', options: ['default' => false])]
    private bool $allowBackorder = false;

    public const KIND_PHYSICAL = 'physical';
    public const KIND_SERVICE = 'service';

    #[ORM\Column(name: 'item_kind', type: 'string', length: 16, options: ['default' => 'physical'])]
    private string $itemKind = self::KIND_PHYSICAL;

    #[ORM\Column(name: 'duration_minutes', type: 'integer', nullable: true)]
    private ?int $durationMinutes = null;

    public function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        ?string $description = null,
        bool $allowBackorder = false,
        bool $service = false,
        ?int $durationMinutes = null,
    ) {
        parent::__construct($tenant);
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->description = self::normalizeDescription($description);
        if ($service && $allowBackorder) {
            throw new DomainException('Un servicio no puede permitir stock negativo.');
        }
        if (!$service && $durationMinutes !== null) {
            throw new DomainException('La duración solo se admite en servicios.');
        }
        self::assertDuration($durationMinutes);
        $this->allowBackorder = $allowBackorder;
        $this->itemKind = $service ? self::KIND_SERVICE : self::KIND_PHYSICAL;
        $this->durationMinutes = $durationMinutes;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function category(): ?ProductCategory
    {
        return $this->category;
    }

    public function assignCategory(?ProductCategory $category): void
    {
        if ($category !== null && $category->tenant()->id() !== $this->tenant()->id()) {
            throw new DomainException('La categoría del producto debe pertenecer al mismo tenant.');
        }
        if ($category !== null && !$category->isActive()) {
            throw new DomainException('No se puede asignar una categoría desactivada.');
        }
        if ($this->category === $category) {
            return;
        }
        $this->category = $category;
        $this->touch();
    }

    public function isService(): bool
    {
        return $this->itemKind === self::KIND_SERVICE;
    }

    public function kind(): string
    {
        return $this->itemKind;
    }

    public function durationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function updateServiceDuration(?int $minutes): void
    {
        if (!$this->isService()) {
            throw new DomainException('Solo un servicio admite duración.');
        }
        self::assertDuration($minutes);
        $this->durationMinutes = $minutes;
        $this->touch();
    }

    private static function assertDuration(?int $minutes): void
    {
        if ($minutes !== null && ($minutes < 1 || $minutes > 525600)) {
            throw new DomainException('La duración del servicio debe estar entre 1 y 525600 minutos.');
        }
    }

    public function allowsBackorder(): bool
    {
        return $this->allowBackorder;
    }

    public function setBackorderAllowed(bool $allowBackorder): void
    {
        if ($this->isService() && $allowBackorder) {
            throw new DomainException('Un servicio no reserva ni permite inventario.');
        }
        if ($this->allowBackorder === $allowBackorder) {
            return;
        }

        $this->allowBackorder = $allowBackorder;
        $this->touch();
    }

    public function update(
        string $name,
        string $slug,
        ?string $description,
    ): void {
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->description = self::normalizeDescription($description);
        $this->touch();
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (
            $slug === ''
            || mb_strlen($slug, 'UTF-8') > 120
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new DomainException(
                'El slug del producto debe usar letras minúsculas, números y guiones, con máximo 120 caracteres.',
            );
        }

        return $slug;
    }

    private static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim($description);
        if ($description === '') {
            return null;
        }

        if (mb_strlen($description, 'UTF-8') > 5000) {
            throw new DomainException(
                'La descripción del producto admite máximo 5000 caracteres.',
            );
        }

        return $description;
    }
}

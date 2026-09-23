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

    #[ORM\Column(name: 'allow_backorder', type: 'boolean', options: ['default' => false])]
    private bool $allowBackorder = false;

    public function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        ?string $description = null,
        bool $allowBackorder = false,
    ) {
        parent::__construct($tenant);
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->description = self::normalizeDescription($description);
        $this->allowBackorder = $allowBackorder;
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

    public function allowsBackorder(): bool
    {
        return $this->allowBackorder;
    }

    public function setBackorderAllowed(bool $allowBackorder): void
    {
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

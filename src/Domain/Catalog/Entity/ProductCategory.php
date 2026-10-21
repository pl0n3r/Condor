<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_product_category')]
#[ORM\UniqueConstraint(name: 'uniq_product_category_tenant_name', columns: ['tenant_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_product_category_tenant_slug', columns: ['tenant_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_product_category_tenant_id', columns: ['tenant_id', 'id'])]
final class ProductCategory extends CatalogItem
{
    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    public function __construct(Tenant $tenant, string $name, string $slug)
    {
        parent::__construct($tenant);
        $this->setIdentity($name, $slug);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function update(string $name, string $slug): void
    {
        $this->setIdentity($name, $slug);
        $this->touch();
    }

    private function setIdentity(string $name, string $slug): void
    {
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException('El nombre de la categoría debe tener de 1 a 160 caracteres.');
        }
        if (
            $slug === ''
            || mb_strlen($slug, 'UTF-8') > 120
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new DomainException('El slug de la categoría es inválido.');
        }
        $this->name = $name;
        $this->slug = $slug;
    }
}

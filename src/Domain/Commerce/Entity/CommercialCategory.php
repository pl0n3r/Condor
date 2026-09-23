<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_commercial_category')]
#[ORM\UniqueConstraint(
    name: 'uniq_commercial_category_tenant_slug',
    columns: ['tenant_id', 'slug'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_commercial_category_tenant_id',
    columns: ['tenant_id', 'id'],
)]
class CommercialCategory extends NamedCommercialItem
{
    private const LABEL = 'la categoría comercial';

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(
        name: 'preferred_price_list_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'RESTRICT',
    )]
    private ?PriceList $preferredPriceList = null;

    public function __construct(Tenant $tenant, string $name, string $slug)
    {
        parent::__construct($tenant, $name, $slug, self::LABEL);
    }

    public function preferredPriceList(): ?PriceList
    {
        return $this->preferredPriceList;
    }

    public function assignPreferredPriceList(?PriceList $priceList): void
    {
        if (
            $priceList !== null
            && $priceList->tenant()->id() !== $this->tenant()->id()
        ) {
            throw new DomainException(
                'La lista preferida debe pertenecer al mismo tenant '
                .'de la categoría comercial.',
            );
        }

        $this->preferredPriceList = $priceList;
        $this->touch();
    }

    public function update(string $name, string $slug): void
    {
        $this->updateIdentity($name, $slug, self::LABEL);
    }
}

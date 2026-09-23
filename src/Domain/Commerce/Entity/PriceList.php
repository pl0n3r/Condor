<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_price_list')]
#[ORM\UniqueConstraint(
    name: 'uniq_price_list_tenant_slug',
    columns: ['tenant_id', 'slug'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_price_list_tenant_id',
    columns: ['tenant_id', 'id'],
)]
class PriceList extends NamedCommercialItem
{
    private const LABEL = 'la lista de precios';

    private const SUPPORTED_CURRENCIES = ['COP'];

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    public function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        string $currency = 'COP',
    ) {
        parent::__construct($tenant, $name, $slug, self::LABEL);
        $this->currency = self::normalizeCurrency($currency);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function update(string $name, string $slug): void
    {
        $this->updateIdentity($name, $slug, self::LABEL);
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = mb_strtoupper(trim($currency), 'UTF-8');
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new DomainException(
                'La moneda no está soportada en esta versión.',
            );
        }

        return $currency;
    }
}

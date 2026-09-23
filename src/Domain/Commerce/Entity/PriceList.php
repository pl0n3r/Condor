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
class PriceList extends CommercialItem
{
    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    public function __construct(
        Tenant $tenant,
        string $name,
        string $slug,
        string $currency = 'COP',
    ) {
        parent::__construct($tenant);
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->currency = self::normalizeCurrency($currency);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function update(string $name, string $slug): void
    {
        $this->name = self::normalizeName($name);
        $this->slug = self::normalizeSlug($slug);
        $this->touch();
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre de la lista de precios es obligatorio '
                .'y admite máximo 160 caracteres.',
            );
        }

        return $name;
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
                'El slug de la lista de precios debe usar letras minúsculas, '
                .'números y guiones.',
            );
        }

        return $slug;
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = mb_strtoupper(trim($currency), 'UTF-8');
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException(
                'La moneda debe usar un código ISO de tres letras.',
            );
        }

        return $currency;
    }
}

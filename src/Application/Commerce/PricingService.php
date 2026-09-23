<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\EffectivePrice;
use App\Domain\Commerce\EffectivePriceResolver;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\PriceRule;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PricingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EffectivePriceResolver $resolver,
    ) {
    }

    public function resolve(
        Tenant $tenant,
        ProductVariant $variant,
        ?PriceList $requestedList = null,
        ?Customer $customer = null,
        ?DateTimeImmutable $at = null,
    ): EffectivePrice {
        $resolved = $this->resolveBatch(
            $tenant,
            [$variant],
            $requestedList,
            $customer,
            $at,
        )[$variant->id()] ?? null;

        if (!$resolved instanceof EffectivePrice) {
            throw new DomainException(
                'La variante no tiene precio en la lista seleccionada.',
            );
        }

        return $resolved;
    }

    /**
     * @param list<ProductVariant> $variants
     * @return array<string, EffectivePrice>
     */
    public function resolveBatch(
        Tenant $tenant,
        array $variants,
        ?PriceList $requestedList = null,
        ?Customer $customer = null,
        ?DateTimeImmutable $at = null,
    ): array {
        if ($variants === []) {
            return [];
        }

        foreach ($variants as $variant) {
            $this->assertVariant($tenant, $variant);
        }

        if (
            $customer !== null
            && $customer->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'El cliente no pertenece al tenant del precio.',
            );
        }

        $category = $customer?->commercialCategory();
        $priceList = $requestedList ?? $category?->preferredPriceList();
        if (!$priceList instanceof PriceList) {
            throw new DomainException(
                'Debes seleccionar una lista de precios '
                .'o configurar una lista preferida para la categoría.',
            );
        }
        if (
            $priceList->tenant()->id() !== $tenant->id()
            || !$priceList->isActive()
        ) {
            throw new DomainException(
                'La lista de precios no está disponible en este tenant.',
            );
        }

        $prices = array_values(array_filter(
            $this->entityManager->getRepository(VariantPrice::class)->findBy([
                'tenant' => $tenant,
                'priceList' => $priceList,
                'variant' => $variants,
            ]),
            static fn (mixed $price): bool => $price instanceof VariantPrice,
        ));
        if ($prices === []) {
            return [];
        }

        $pricesByVariant = [];
        foreach ($prices as $price) {
            $pricesByVariant[$price->variant()->id()] = $price;
        }

        $rules = array_values(array_filter(
            $this->entityManager->getRepository(PriceRule::class)->findBy([
                'tenant' => $tenant,
                'priceList' => $priceList,
                'active' => true,
            ]),
            static fn (mixed $rule): bool => $rule instanceof PriceRule,
        ));

        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $resolved = [];
        foreach ($variants as $variant) {
            $price = $pricesByVariant[$variant->id()] ?? null;
            if (!$price instanceof VariantPrice) {
                continue;
            }

            $resolved[$variant->id()] = $this->resolver->resolve(
                $price,
                $category,
                $rules,
                $at,
            );
        }

        return $resolved;
    }

    private function assertVariant(
        Tenant $tenant,
        ProductVariant $variant,
    ): void {
        if (
            $variant->tenant()->id() !== $tenant->id()
            || !$variant->isActive()
            || !$variant->product()->isActive()
        ) {
            throw new DomainException(
                'La variante no está disponible en este tenant.',
            );
        }
    }
}

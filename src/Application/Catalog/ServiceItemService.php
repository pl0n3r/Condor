<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * D-028: same SKU, variant and list pricing as physical goods, never stock.
 * No agenda, external providers, user data or new identity records.
 */
final readonly class ServiceItemService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{product: Product, variant: ProductVariant, price: VariantPrice}
     */
    public function create(
        Tenant $tenant,
        PriceList $priceList,
        string $name,
        string $slug,
        string $sku,
        int $amountMinor,
        ?int $durationMinutes = null,
        ?string $description = null,
    ): array {
        if (
            $priceList->tenant()->id() !== $tenant->id()
            || !$priceList->isActive()
        ) {
            throw new DomainException('La lista de precios no pertenece al tenant o está inactiva.');
        }

        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $manager) use (
                $tenant,
                $priceList,
                $name,
                $slug,
                $sku,
                $amountMinor,
                $durationMinutes,
                $description,
            ): array {
                $product = new Product(
                    $tenant,
                    $name,
                    $slug,
                    $description,
                    false,
                    true,
                    $durationMinutes,
                );
                $variant = new ProductVariant($tenant, $product, $sku, $name);
                $price = new VariantPrice(
                    $tenant,
                    $priceList,
                    $variant,
                    $amountMinor,
                );
                $manager->persist($product);
                $manager->persist($variant);
                $manager->persist($price);

                return [
                    'product' => $product,
                    'variant' => $variant,
                    'price' => $price,
                ];
            },
        );
    }

    /** @return list<array<string, mixed>> */
    public function listForTenant(Tenant $tenant): array
    {
        $products = $this->entityManager->getRepository(Product::class)->findBy(
            ['tenant' => $tenant, 'itemKind' => Product::KIND_SERVICE, 'active' => true],
            ['name' => 'ASC'],
        );
        if ($products === []) {
            return [];
        }

        $variants = $this->entityManager->getRepository(ProductVariant::class)->findBy(
            ['tenant' => $tenant, 'product' => $products, 'active' => true],
        );
        $prices = $variants === [] ? [] : $this->entityManager
            ->getRepository(VariantPrice::class)->findBy([
                'tenant' => $tenant,
                'variant' => $variants,
            ]);

        $pricesByVariant = [];
        foreach ($prices as $price) {
            if ($price instanceof VariantPrice
                && $price->priceList()->tenant()->id() === $tenant->id()
                && $price->priceList()->isActive()
                && $price->variant()->tenant()->id() === $tenant->id()) {
                $pricesByVariant[$price->variant()->id()][] = [
                    'price_list_id' => $price->priceList()->id(),
                    'amount_minor' => $price->amountMinor(),
                    'currency' => $price->priceList()->currency(),
                ];
            }
        }

        $byProduct = [];
        foreach ($variants as $variant) {
            if (!$variant instanceof ProductVariant
                || $variant->tenant()->id() !== $tenant->id()
                || !$variant->product()->isService()) {
                continue;
            }
            $byProduct[$variant->product()->id()][] = [
                'id' => $variant->id(),
                'sku' => $variant->sku(),
                'name' => $variant->name(),
                'prices' => $pricesByVariant[$variant->id()] ?? [],
            ];
        }

        $items = [];
        foreach ($products as $product) {
            if (!$product instanceof Product
                || $product->tenant()->id() !== $tenant->id()
                || !$product->isService()) {
                continue;
            }
            $items[] = [
                'id' => $product->id(),
                'name' => $product->name(),
                'slug' => $product->slug(),
                'description' => $product->description(),
                'kind' => $product->kind(),
                'duration_minutes' => $product->durationMinutes(),
                'variants' => $byProduct[$product->id()] ?? [],
            ];
        }

        return $items;
    }
}

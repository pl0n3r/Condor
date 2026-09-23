<?php

declare(strict_types=1);

namespace App\Application\Storefront;

use App\Application\Commerce\PricingService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PublicCatalogPresentation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PricingService $pricing,
    ) {
    }

    /**
     * @return array{
     *   channel: null|array{id:string,name:string,slug:string,type:string},
     *   products: list<array{
     *     id:string,
     *     name:string,
     *     slug:string,
     *     description:?string,
     *     variants:list<array{
     *       id:string,
     *       sku:string,
     *       name:string,
     *       price:array{
     *         base_amount_minor:int,
     *         effective_amount_minor:int,
     *         currency:string,
     *         price_list_id:string,
     *         rule_id:?string
     *       },
     *       availability:string,
     *       backorder:bool
     *     }>
     *   }>
     * }
     */
    public function catalog(Tenant $tenant): array
    {
        $channel = $this->entityManager
            ->getRepository(SalesChannel::class)
            ->findOneBy([
                'tenant' => $tenant,
                'type' => SalesChannel::TYPE_ECOMMERCE,
                'active' => true,
            ]);

        if (
            !$channel instanceof SalesChannel
            || !$channel->isPublishable()
        ) {
            return ['channel' => null, 'products' => []];
        }

        $products = array_values(array_filter(
            $this->entityManager->getRepository(Product::class)->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            ),
            static fn (mixed $product): bool => $product instanceof Product,
        ));

        $payload = [];
        foreach ($products as $product) {
            $variants = array_values(array_filter(
                $this->entityManager
                    ->getRepository(ProductVariant::class)
                    ->findBy(
                        [
                            'tenant' => $tenant,
                            'product' => $product,
                            'active' => true,
                        ],
                        ['name' => 'ASC'],
                    ),
                static fn (mixed $variant): bool => (
                    $variant instanceof ProductVariant
                ),
            ));

            $variantPayload = [];
            foreach ($variants as $variant) {
                try {
                    $price = $this->pricing->resolve(
                        $tenant,
                        $variant,
                        $channel->priceList(),
                    );
                } catch (DomainException) {
                    continue;
                }

                $balance = $this->entityManager
                    ->getRepository(InventoryBalance::class)
                    ->findOneBy([
                        'tenant' => $tenant,
                        'legalEntity' => $channel->legalEntity(),
                        'source' => $channel->inventorySource(),
                        'variant' => $variant,
                    ]);
                $quantity = $balance instanceof InventoryBalance
                    ? $balance->quantity()
                    : 0;
                $backorder = $product->allowsBackorder();
                $available = $quantity > 0 || $backorder;

                $variantPayload[] = [
                    'id' => $variant->id(),
                    'sku' => $variant->sku(),
                    'name' => $variant->name(),
                    'price' => [
                        'base_amount_minor' => $price->baseAmountMinor,
                        'effective_amount_minor' => $price->effectiveAmountMinor,
                        'currency' => $price->currency,
                        'price_list_id' => $price->priceListId,
                        'rule_id' => $price->ruleId,
                    ],
                    'availability' => $available
                        ? 'available'
                        : 'out_of_stock',
                    'backorder' => $backorder,
                ];
            }

            if ($variantPayload === []) {
                continue;
            }

            $payload[] = [
                'id' => $product->id(),
                'name' => $product->name(),
                'slug' => $product->slug(),
                'description' => $product->description(),
                'variants' => $variantPayload,
            ];
        }

        return [
            'channel' => [
                'id' => $channel->id(),
                'name' => $channel->name(),
                'slug' => $channel->slug(),
                'type' => $channel->type(),
            ],
            'products' => $payload,
        ];
    }
}

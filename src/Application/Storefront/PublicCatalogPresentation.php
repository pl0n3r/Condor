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
use InvalidArgumentException;

final readonly class PublicCatalogPresentation
{
    public const PAGE_SIZE = 24;
    public const MAX_PAGE = 1000;

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
     *   }>,
     *   pagination: array{
     *     page:int,
     *     page_size:int,
     *     has_previous:bool,
     *     has_next:bool
     *   }
     * }
     */
    public function catalog(Tenant $tenant, int $page = 1): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException(
                'La página del catálogo está fuera del rango permitido.',
            );
        }

        if (!$this->salesChannelSchemaReady()) {
            return $this->emptyCatalog($page);
        }

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
            return $this->emptyCatalog($page);
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $products = array_values(array_filter(
            $this->entityManager->getRepository(Product::class)->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC', 'id' => 'ASC'],
                self::PAGE_SIZE + 1,
                $offset,
            ),
            static fn (mixed $product): bool => $product instanceof Product,
        ));
        $hasNext = count($products) > self::PAGE_SIZE;
        if ($hasNext) {
            array_pop($products);
        }

        $variants = $products === []
            ? []
            : array_values(array_filter(
                $this->entityManager
                    ->getRepository(ProductVariant::class)
                    ->findBy(
                        [
                            'tenant' => $tenant,
                            'product' => $products,
                            'active' => true,
                        ],
                        ['name' => 'ASC', 'id' => 'ASC'],
                    ),
                static fn (mixed $variant): bool => (
                    $variant instanceof ProductVariant
                ),
            ));

        $prices = $this->pricing->resolveBatch(
            $tenant,
            $variants,
            $channel->priceList(),
        );

        $balances = $variants === []
            ? []
            : array_values(array_filter(
                $this->entityManager
                    ->getRepository(InventoryBalance::class)
                    ->findBy([
                        'tenant' => $tenant,
                        'legalEntity' => $channel->legalEntity(),
                        'source' => $channel->inventorySource(),
                        'variant' => $variants,
                    ]),
                static fn (mixed $balance): bool => (
                    $balance instanceof InventoryBalance
                ),
            ));
        $balancesByVariant = [];
        foreach ($balances as $balance) {
            $balancesByVariant[$balance->variant()->id()] = $balance;
        }

        $variantsByProduct = [];
        foreach ($variants as $variant) {
            $price = $prices[$variant->id()] ?? null;
            if ($price === null) {
                continue;
            }

            $balance = $balancesByVariant[$variant->id()] ?? null;
            $quantity = $balance instanceof InventoryBalance
                ? $balance->quantity()
                : 0;
            $product = $variant->product();
            $backorder = $product->allowsBackorder();
            $available = $quantity > 0 || $backorder;

            $variantsByProduct[$product->id()][] = [
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

        $payload = [];
        foreach ($products as $product) {
            $variantPayload = $variantsByProduct[$product->id()] ?? [];
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
            'pagination' => [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'has_previous' => $page > 1,
                'has_next' => $hasNext,
            ],
        ];
    }

    private function salesChannelSchemaReady(): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                LIMIT 1
                SQL,
            ['condor_sales_channel'],
        );
    }

    /**
     * @return array{
     *   channel:null,
     *   products:list<never>,
     *   pagination:array{
     *     page:int,
     *     page_size:int,
     *     has_previous:bool,
     *     has_next:bool
     *   }
     * }
     */
    private function emptyCatalog(int $page): array
    {
        return [
            'channel' => null,
            'products' => [],
            'pagination' => [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'has_previous' => $page > 1,
                'has_next' => false,
            ],
        ];
    }
}

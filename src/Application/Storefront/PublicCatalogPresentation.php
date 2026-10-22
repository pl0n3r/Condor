<?php

declare(strict_types=1);

namespace App\Application\Storefront;

use App\Application\Commerce\PricingService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductCategory;
use App\Domain\Catalog\Entity\ProductMedia;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
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
     *   filters: array{q:string,categoria:string},
     *   categories: list<array{name:string,slug:string}>,
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
     *     has_next:bool,
     *     previous_href:?string,
     *     next_href:?string
     *   }
     * }
     */
    public function catalog(
        Tenant $tenant,
        int $page = 1,
        ?PublicCatalogSearch $search = null,
    ): array {
        $search ??= new PublicCatalogSearch(page: $page);
        $page = $search->page();
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException(
                'La página del catálogo está fuera del rango permitido.',
            );
        }

        if (!$this->salesChannelSchemaReady()) {
            return $this->emptyCatalog($search);
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
            return $this->emptyCatalog($search);
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $publishablePrice = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(VariantPrice::class, 'publishable_price')
            ->innerJoin('publishable_price.variant', 'publishable_variant')
            ->where('publishable_price.tenant = :tenant')
            ->andWhere('publishable_price.priceList = :price_list')
            ->andWhere('publishable_variant.tenant = :tenant')
            ->andWhere('publishable_variant.product = product')
            ->andWhere('publishable_variant.active = true');

        // Category names and identities always come from this tenant.
        $categoryOptions = [];
        foreach ($this->entityManager->getRepository(ProductCategory::class)->findBy(
            ['tenant' => $tenant, 'active' => true],
            ['name' => 'ASC', 'id' => 'ASC'],
            100,
        ) as $categoryOption) {
            if ($categoryOption instanceof ProductCategory) {
                $categoryOptions[] = [
                    'name' => $categoryOption->name(),
                    'slug' => $categoryOption->slug(),
                ];
            }
        }

        $productQuery = $this->entityManager->createQueryBuilder()
            ->select('product')
            ->from(Product::class, 'product')
            ->where('product.tenant = :tenant')
            ->andWhere('product.active = true')
            ->andWhere(
                $this->entityManager
                    ->createQueryBuilder()
                    ->expr()
                    ->exists($publishablePrice->getDQL()),
            )
            ->setParameter('tenant', $tenant)
            ->setParameter('price_list', $channel->priceList());

        if ($search->term() !== null) {
            // LOCATE treats % and _ as literal characters, not SQL wildcards.
            $productQuery
                ->andWhere('(LOCATE(LOWER(:search_term), LOWER(product.name)) > 0 OR LOCATE(LOWER(:search_term), LOWER(product.description)) > 0)')
                ->setParameter('search_term', $search->term());
        }
        if ($search->categorySlug() !== null) {
            $category = $this->entityManager->getRepository(ProductCategory::class)
                ->findOneBy([
                    'tenant' => $tenant,
                    'slug' => $search->categorySlug(),
                    'active' => true,
                ]);
            if ($category instanceof ProductCategory) {
                $productQuery
                    ->andWhere('product.category = :selected_category')
                    ->setParameter('selected_category', $category);
            } else {
                // Unknown, foreign or inactive categories cannot widen results.
                $productQuery->andWhere('1 = 0');
            }
        }

        $products = array_values(array_filter(
            $productQuery
                ->orderBy('product.name', \SortDirection::Ascending)
                ->addOrderBy('product.id', \SortDirection::Ascending)
                ->setFirstResult($offset)
                ->setMaxResults(self::PAGE_SIZE + 1)
                ->getQuery()
                ->getResult(),
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
                ? $balance->sellableQuantity()
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

        // An older pre-deploy schema must not break the public catalog.
        $photosByProduct = [];
        if ($products !== [] && $this->productMediaSchemaReady()) {
            $photos = $this->entityManager->getRepository(ProductMedia::class)->findBy(
                ['tenant' => $tenant, 'product' => $products, 'active' => true],
                ['sortOrder' => 'ASC', 'id' => 'ASC'],
            );
            foreach ($photos as $photo) {
                if (!$photo->product()->isActive()) continue;
                $photosByProduct[$photo->product()->id()][] = $photo;
            }
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
                'primary_image' => $this->mediaPayload($photosByProduct[$product->id()] ?? [], null),
                'variants' => array_map(
                    fn (array $item): array => $item + [
                        'primary_image' => $this->mediaPayload(
                            $photosByProduct[$product->id()] ?? [],
                            $item['id'],
                        ),
                    ],
                    $variantPayload,
                ),
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
            'filters' => $search->filters(),
            'categories' => $categoryOptions,
            'pagination' => [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'has_previous' => $page > 1,
                'has_next' => $hasNext && $page < self::MAX_PAGE,
                'previous_href' => $page > 1 ? $search->pageHref($page - 1) : null,
                'next_href' => $hasNext && $page < self::MAX_PAGE
                    ? $search->pageHref($page + 1) : null,
            ],
        ];
    }

    /**
     * @param list<ProductMedia> $photos
     * @return null|array{id:string,url:string,mime_type:string}
     */
    private function mediaPayload(array $photos, ?string $variantId): ?array
    {
        foreach ($photos as $photo) {
            if (($photo->variant()?->id()) !== $variantId || !$photo->isPrimary()) continue;
            return [
                'id' => $photo->id(),
                'url' => $photo->filePath(),
                'mime_type' => $photo->mimeType(),
            ];
        }
        return null;
    }

    private function productMediaSchemaReady(): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1
                SQL,
            ['condor_product_media'],
        );
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
     *   filters:array{q:string,categoria:string},
     *   categories:list<never>,
     *   products:list<never>,
     *   pagination:array{
     *     page:int,
     *     page_size:int,
     *     has_previous:bool,
     *     has_next:bool,
     *     previous_href:?string,
     *     next_href:?string
     *   }
     * }
     */
    private function emptyCatalog(PublicCatalogSearch $search): array
    {
        $page = $search->page();
        return [
            'channel' => null,
            'filters' => $search->filters(),
            'categories' => [],
            'products' => [],
            'pagination' => [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'has_previous' => $page > 1,
                'has_next' => false,
                'previous_href' => $page > 1 ? $search->pageHref($page - 1) : null,
                'next_href' => null,
            ],
        ];
    }
}

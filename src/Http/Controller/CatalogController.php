<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products',
        name: 'api_catalog_products',
        methods: ['GET'],
    )]
    public function products(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedScope($branchId, 'catalog.view');

        $products = $this->entityManager->getRepository(Product::class)->findBy(
            ['tenant' => $tenant, 'active' => true],
            ['name' => 'ASC'],
        );
        $variants = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );

        $variantsByProduct = [];
        foreach ($variants as $variant) {
            if (
                !$variant instanceof ProductVariant
                || !$variant->product()->isActive()
            ) {
                continue;
            }

            $variantsByProduct[$variant->product()->id()][] = self::variantPayload(
                $variant,
            );
        }

        $payload = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $payload[] = self::productPayload(
                $product,
                $variantsByProduct[$product->id()] ?? [],
            );
        }

        return $this->json(['products' => $payload]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products',
        name: 'api_catalog_products_create',
        methods: ['POST'],
    )]
    public function createProduct(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'catalog.create',
        );
        $payload = $this->payload(
            $request,
            ['name', 'slug', 'description'],
        );

        $product = $this->domain(
            fn (): Product => new Product(
                $tenant,
                $this->requiredString($payload, 'name'),
                $this->requiredString($payload, 'slug'),
                $this->nullableString($payload, 'description'),
            ),
        );
        $this->entityManager->persist($product);
        $this->audit(
            $tenant,
            $user,
            'product.created',
            Product::class,
            $product->id(),
            ['branch_id' => $branch->id()],
        );
        $this->flushUnique(
            'Ya existe un producto con ese slug en la empresa.',
        );

        return $this->json(
            ['product' => self::productPayload($product, [])],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products/{productId}',
        name: 'api_catalog_products_update',
        methods: ['PATCH'],
    )]
    public function updateProduct(
        string $branchId,
        string $productId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'catalog.update',
        );
        $product = $this->product($productId, $tenant);
        $payload = $this->payload(
            $request,
            ['name', 'slug', 'description'],
        );
        if ($payload === []) {
            throw new UnprocessableEntityHttpException(
                'Debes enviar al menos un campo para actualizar.',
            );
        }

        $name = array_key_exists('name', $payload)
            ? $this->requiredString($payload, 'name')
            : $product->name();
        $slug = array_key_exists('slug', $payload)
            ? $this->requiredString($payload, 'slug')
            : $product->slug();
        $description = array_key_exists('description', $payload)
            ? $this->nullableString($payload, 'description')
            : $product->description();

        $this->domain(
            static fn (): null => self::updateProductEntity(
                $product,
                $name,
                $slug,
                $description,
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'product.updated',
            Product::class,
            $product->id(),
            ['branch_id' => $branch->id()],
        );
        $this->flushUnique(
            'Ya existe un producto con ese slug en la empresa.',
        );

        return $this->json([
            'product' => self::productPayload(
                $product,
                $this->variantPayloads($tenant, $product),
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products/{productId}',
        name: 'api_catalog_products_delete',
        methods: ['DELETE'],
    )]
    public function deleteProduct(
        string $branchId,
        string $productId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'catalog.delete',
        );
        $product = $this->product($productId, $tenant);

        $variantIds = [];
        foreach (
            $this->entityManager->getRepository(ProductVariant::class)->findBy([
                'tenant' => $tenant,
                'product' => $product,
                'active' => true,
            ]) as $variant
        ) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            $variant->deactivate();
            $variantIds[] = $variant->id();
        }

        $product->deactivate();
        $this->audit(
            $tenant,
            $user,
            'product.deactivated',
            Product::class,
            $product->id(),
            [
                'branch_id' => $branch->id(),
                'variant_ids' => $variantIds,
            ],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products/{productId}/variants',
        name: 'api_catalog_variants_create',
        methods: ['POST'],
    )]
    public function createVariant(
        string $branchId,
        string $productId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'catalog.create',
        );
        $product = $this->product($productId, $tenant);
        $payload = $this->payload($request, ['sku', 'name']);

        $variant = $this->domain(
            fn (): ProductVariant => new ProductVariant(
                $tenant,
                $product,
                $this->requiredString($payload, 'sku'),
                $this->requiredString($payload, 'name'),
            ),
        );
        $this->entityManager->persist($variant);
        $this->audit(
            $tenant,
            $user,
            'product_variant.created',
            ProductVariant::class,
            $variant->id(),
            [
                'branch_id' => $branch->id(),
                'product_id' => $product->id(),
            ],
        );
        $this->flushUnique(
            'Ya existe una variante con ese SKU en la empresa.',
        );

        return $this->json(
            ['variant' => self::variantPayload($variant)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products/{productId}/variants/{variantId}',
        name: 'api_catalog_variants_update',
        methods: ['PATCH'],
    )]
    public function updateVariant(
        string $branchId,
        string $productId,
        string $variantId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'catalog.update',
        );
        $product = $this->product($productId, $tenant);
        $variant = $this->variant($variantId, $tenant, $product);
        $payload = $this->payload($request, ['sku', 'name']);
        if ($payload === []) {
            throw new UnprocessableEntityHttpException(
                'Debes enviar al menos un campo para actualizar.',
            );
        }

        $sku = array_key_exists('sku', $payload)
            ? $this->requiredString($payload, 'sku')
            : $variant->sku();
        $name = array_key_exists('name', $payload)
            ? $this->requiredString($payload, 'name')
            : $variant->name();

        $this->domain(
            static fn (): null => self::updateVariantEntity(
                $variant,
                $sku,
                $name,
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'product_variant.updated',
            ProductVariant::class,
            $variant->id(),
            [
                'branch_id' => $branch->id(),
                'product_id' => $product->id(),
            ],
        );
        $this->flushUnique(
            'Ya existe una variante con ese SKU en la empresa.',
        );

        return $this->json([
            'variant' => self::variantPayload($variant),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/products/{productId}/variants/{variantId}',
        name: 'api_catalog_variants_delete',
        methods: ['DELETE'],
    )]
    public function deleteVariant(
        string $branchId,
        string $productId,
        string $variantId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'catalog.delete',
        );
        $product = $this->product($productId, $tenant);
        $variant = $this->variant($variantId, $tenant, $product);

        $variant->deactivate();
        $this->audit(
            $tenant,
            $user,
            'product_variant.deactivated',
            ProductVariant::class,
            $variant->id(),
            [
                'branch_id' => $branch->id(),
                'product_id' => $product->id(),
            ],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return array{0: User, 1: Tenant, 2: Branch} */
    private function authorizedScope(
        string $branchId,
        string $permission,
    ): array {
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            $permission,
        );

        return [$user, $tenant, $branch];
    }

    private function product(string $productId, Tenant $tenant): Product
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy([
            'id' => $productId,
            'tenant' => $tenant,
            'active' => true,
        ]);
        if (!$product instanceof Product) {
            throw new NotFoundHttpException('Producto no encontrado.');
        }

        return $product;
    }

    private function variant(
        string $variantId,
        Tenant $tenant,
        Product $product,
    ): ProductVariant {
        $variant = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findOneBy([
                'id' => $variantId,
                'tenant' => $tenant,
                'product' => $product,
                'active' => true,
            ]);
        if (!$variant instanceof ProductVariant) {
            throw new NotFoundHttpException('Variante no encontrada.');
        }

        return $variant;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser texto.', $field),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function nullableString(
        array $payload,
        string $field,
    ): ?string {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }

        if (!is_string($payload[$field])) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s debe ser texto o null.', $field),
            );
        }

        return $payload[$field];
    }

    private function flushUnique(string $message): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException($message, $exception);
        }
    }

    /** @return list<array<string, mixed>> */
    private function variantPayloads(
        Tenant $tenant,
        Product $product,
    ): array {
        $payload = [];
        $variants = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findBy(
                [
                    'tenant' => $tenant,
                    'product' => $product,
                    'active' => true,
                ],
                ['name' => 'ASC'],
            );

        foreach ($variants as $variant) {
            if ($variant instanceof ProductVariant) {
                $payload[] = self::variantPayload($variant);
            }
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return array<string, mixed>
     */
    private static function productPayload(
        Product $product,
        array $variants,
    ): array {
        return [
            'id' => $product->id(),
            'name' => $product->name(),
            'slug' => $product->slug(),
            'description' => $product->description(),
            'variants' => $variants,
        ];
    }

    /** @return array<string, mixed> */
    private static function variantPayload(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id(),
            'sku' => $variant->sku(),
            'name' => $variant->name(),
        ];
    }

    private static function updateProductEntity(
        Product $product,
        string $name,
        string $slug,
        ?string $description,
    ): null {
        $product->update($name, $slug, $description);

        return null;
    }

    private static function updateVariantEntity(
        ProductVariant $variant,
        string $sku,
        string $name,
    ): null {
        $variant->update($sku, $name);

        return null;
    }
}

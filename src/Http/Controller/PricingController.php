<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commerce\PricingService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\PriceRule;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PricingController extends AbstractController
{
    use BranchApiSupport;
    use CommerceApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly PricingService $pricing,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing',
        name: 'api_pricing_snapshot',
        methods: ['GET'],
    )]
    public function snapshot(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope(
            $branchId,
            'pricing.view',
        );

        return $this->json([
            'price_lists' => $this->priceListPayloads($tenant),
            'variants' => $this->variantPayloads($tenant),
            'variant_prices' => $this->variantPricePayloads($tenant),
            'rules' => $this->rulePayloads($tenant),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/lists',
        name: 'api_price_lists_create',
        methods: ['POST'],
    )]
    public function createList(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.create',
        );
        $payload = $this->payload(
            $request,
            ['name', 'slug', 'currency'],
        );
        $name = $this->commercialRequiredString($payload, 'name');
        $slug = $this->commercialRequiredString($payload, 'slug');
        $currency = array_key_exists('currency', $payload)
            ? $this->commercialRequiredString($payload, 'currency')
            : 'COP';
        $inactive = $this->entityManager
            ->getRepository(PriceList::class)
            ->findOneBy([
                'tenant' => $tenant,
                'slug' => strtolower(trim($slug)),
                'active' => false,
            ]);
        $reactivated = $inactive instanceof PriceList;

        $list = $this->domain(function () use (
            $inactive,
            $tenant,
            $name,
            $slug,
            $currency,
        ): PriceList {
            if ($inactive instanceof PriceList) {
                $probe = new PriceList($tenant, $name, $slug, $currency);
                if ($probe->currency() !== $inactive->currency()) {
                    throw new \DomainException(
                        'No puedes cambiar la moneda al reactivar una lista.',
                    );
                }
                $inactive->update($name, $slug);
                $inactive->activate();

                return $inactive;
            }

            return new PriceList($tenant, $name, $slug, $currency);
        });
        $this->entityManager->persist($list);
        $this->audit(
            $tenant,
            $user,
            $reactivated
                ? 'price_list.reactivated'
                : 'price_list.created',
            PriceList::class,
            $list->id(),
            ['branch_id' => $branch->id()],
        );
        $this->commercialFlushUnique(
            'Ya existe una lista de precios con ese slug.',
        );

        return $this->json(
            ['price_list' => self::priceListPayload($list)],
            $reactivated ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/lists/{listId}',
        name: 'api_price_lists_update',
        methods: ['PATCH'],
    )]
    public function updateList(
        string $branchId,
        string $listId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.update',
        );
        $list = $this->priceList($tenant, $listId);
        $payload = $this->payload($request, ['name', 'slug']);

        $this->domain(function () use ($list, $payload): void {
            $list->update(
                array_key_exists('name', $payload)
                    ? $this->commercialRequiredString($payload, 'name')
                    : $list->name(),
                array_key_exists('slug', $payload)
                    ? $this->commercialRequiredString($payload, 'slug')
                    : $list->slug(),
            );
        });
        $this->audit(
            $tenant,
            $user,
            'price_list.updated',
            PriceList::class,
            $list->id(),
            ['branch_id' => $branch->id()],
        );
        $this->commercialFlushUnique(
            'Ya existe una lista de precios con ese slug.',
        );

        return $this->json([
            'price_list' => self::priceListPayload($list),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/lists/{listId}',
        name: 'api_price_lists_delete',
        methods: ['DELETE'],
    )]
    public function deleteList(
        string $branchId,
        string $listId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.delete',
        );
        $list = $this->priceList($tenant, $listId);
        $preferredBy = $this->entityManager
            ->getRepository(CommercialCategory::class)
            ->findOneBy([
                'tenant' => $tenant,
                'preferredPriceList' => $list,
                'active' => true,
            ]);
        if ($preferredBy instanceof CommercialCategory) {
            throw new ConflictHttpException(
                'No puedes desactivar una lista preferida por una categoría activa.',
            );
        }

        $list->deactivate();
        $this->audit(
            $tenant,
            $user,
            'price_list.deactivated',
            PriceList::class,
            $list->id(),
            ['branch_id' => $branch->id()],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/lists/{listId}/variants/{variantId}',
        name: 'api_variant_prices_upsert',
        methods: ['PUT'],
    )]
    public function upsertVariantPrice(
        string $branchId,
        string $listId,
        string $variantId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.update',
        );
        $list = $this->priceList($tenant, $listId);
        $variant = $this->variant($tenant, $variantId);
        $payload = $this->payload($request, ['amount_minor']);
        $amount = $this->commercialRequiredInt(
            $payload,
            'amount_minor',
        );

        $price = $this->entityManager
            ->getRepository(VariantPrice::class)
            ->findOneBy([
                'tenant' => $tenant,
                'priceList' => $list,
                'variant' => $variant,
            ]);
        $created = !$price instanceof VariantPrice;
        $previousAmount = $price instanceof VariantPrice
            ? $price->amountMinor()
            : null;
        $price = $this->domain(function () use (
            $price,
            $tenant,
            $list,
            $variant,
            $amount,
        ): VariantPrice {
            if ($price instanceof VariantPrice) {
                $price->updateAmount($amount);

                return $price;
            }

            return new VariantPrice(
                $tenant,
                $list,
                $variant,
                $amount,
            );
        });
        $this->entityManager->persist($price);
        $this->audit(
            $tenant,
            $user,
            $created ? 'variant_price.created' : 'variant_price.updated',
            VariantPrice::class,
            $price->id(),
            [
                'branch_id' => $branch->id(),
                'price_list_id' => $list->id(),
                'variant_id' => $variant->id(),
                'previous_amount_minor' => $previousAmount,
                'amount_minor' => $price->amountMinor(),
            ],
        );
        $this->commercialFlushUnique(
            'Ya existe un precio para esa variante y lista.',
        );

        return $this->json(
            ['variant_price' => self::variantPricePayload($price)],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/categories/{categoryId}',
        name: 'api_category_preferred_price_list',
        methods: ['PATCH'],
    )]
    public function assignCategoryList(
        string $branchId,
        string $categoryId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.update',
        );
        $category = $this->category($tenant, $categoryId);
        $payload = $this->payload(
            $request,
            ['preferred_price_list_id'],
        );
        $listId = $this->commercialNullableString(
            $payload,
            'preferred_price_list_id',
        );
        $list = $listId === null
            ? null
            : $this->priceList($tenant, $listId);
        $previousPriceListId = $category->preferredPriceList()?->id();

        $this->domain(
            static fn (): null => self::assignPreferredList(
                $category,
                $list,
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'commercial_category.price_list_assigned',
            CommercialCategory::class,
            $category->id(),
            [
                'branch_id' => $branch->id(),
                'previous_price_list_id' => $previousPriceListId,
                'price_list_id' => $list?->id(),
            ],
        );
        $this->entityManager->flush();

        return $this->json([
            'category' => [
                'id' => $category->id(),
                'preferred_price_list_id' => $list?->id(),
            ],
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/rules',
        name: 'api_price_rules_create',
        methods: ['POST'],
    )]
    public function createRule(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.create',
        );
        $payload = $this->payload(
            $request,
            [
                'price_list_id',
                'category_id',
                'name',
                'priority',
                'discount_type',
                'discount_value',
                'valid_from',
                'valid_until',
            ],
        );
        $list = $this->priceList(
            $tenant,
            $this->commercialRequiredString(
                $payload,
                'price_list_id',
            ),
        );
        $category = $this->optionalCategory(
            $tenant,
            $this->commercialNullableString($payload, 'category_id'),
        );

        $rule = $this->domain(
            fn (): PriceRule => new PriceRule(
                $tenant,
                $list,
                $this->commercialRequiredString($payload, 'name'),
                $this->commercialRequiredInt($payload, 'priority'),
                $this->commercialRequiredString(
                    $payload,
                    'discount_type',
                ),
                $this->commercialRequiredInt(
                    $payload,
                    'discount_value',
                ),
                $category,
                $this->optionalDate($payload, 'valid_from'),
                $this->optionalDate($payload, 'valid_until'),
            ),
        );
        $this->entityManager->persist($rule);
        $this->audit(
            $tenant,
            $user,
            'price_rule.created',
            PriceRule::class,
            $rule->id(),
            [
                'branch_id' => $branch->id(),
                'price_list_id' => $list->id(),
                'commercial_category_id' => $category?->id(),
            ],
        );
        $this->entityManager->flush();

        return $this->json(
            ['rule' => self::rulePayload($rule)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/rules/{ruleId}',
        name: 'api_price_rules_delete',
        methods: ['DELETE'],
    )]
    public function deleteRule(
        string $branchId,
        string $ruleId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'pricing.delete',
        );
        $rule = $this->entityManager
            ->getRepository(PriceRule::class)
            ->findOneBy([
                'id' => $ruleId,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$rule instanceof PriceRule) {
            throw new NotFoundHttpException(
                'Regla de precio no encontrada.',
            );
        }

        $rule->deactivate();
        $this->audit(
            $tenant,
            $user,
            'price_rule.deactivated',
            PriceRule::class,
            $rule->id(),
            [
                'branch_id' => $branch->id(),
                'price_list_id' => $rule->priceList()->id(),
                'commercial_category_id' => $rule
                    ->commercialCategory()?->id(),
            ],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/v1/branches/{branchId}/pricing/effective',
        name: 'api_effective_price',
        methods: ['GET'],
    )]
    public function effective(
        string $branchId,
        Request $request,
    ): JsonResponse {
        [, $tenant] = $this->authorizedBranchScope(
            $branchId,
            'pricing.view',
        );
        $variantId = trim(
            (string) $request->query->get('variant', ''),
        );
        if ($variantId === '') {
            throw new UnprocessableEntityHttpException(
                'Debes indicar la variante.',
            );
        }
        $variant = $this->variant($tenant, $variantId);

        $listId = trim(
            (string) $request->query->get('price_list', ''),
        );
        $customerId = trim(
            (string) $request->query->get('customer', ''),
        );
        $list = $listId === ''
            ? null
            : $this->priceList($tenant, $listId);
        $customer = $customerId === ''
            ? null
            : $this->customer($tenant, $customerId);

        $effective = $this->domain(
            fn () => $this->pricing->resolve(
                $tenant,
                $variant,
                $list,
                $customer,
            ),
        );

        return $this->json([
            'effective_price' => [
                'base_amount_minor' => $effective->baseAmountMinor,
                'amount_minor' => $effective->effectiveAmountMinor,
                'currency' => $effective->currency,
                'price_list_id' => $effective->priceListId,
                'rule_id' => $effective->ruleId,
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function priceListPayloads(Tenant $tenant): array
    {
        $lists = $this->entityManager
            ->getRepository(PriceList::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );

        return array_values(array_map(
            static fn (PriceList $list): array => self::priceListPayload(
                $list,
            ),
            array_filter(
                $lists,
                static fn (mixed $value): bool => $value instanceof PriceList,
            ),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function variantPayloads(Tenant $tenant): array
    {
        $variants = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );

        return array_values(array_map(
            static fn (ProductVariant $variant): array => [
                'id' => $variant->id(),
                'sku' => $variant->sku(),
                'name' => $variant->name(),
                'product_name' => $variant->product()->name(),
            ],
            array_filter(
                $variants,
                static fn (mixed $value): bool => (
                    $value instanceof ProductVariant
                    && $value->product()->isActive()
                ),
            ),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function variantPricePayloads(Tenant $tenant): array
    {
        $prices = $this->entityManager
            ->getRepository(VariantPrice::class)
            ->findBy(['tenant' => $tenant]);

        return array_values(array_map(
            static fn (VariantPrice $price): array => (
                self::variantPricePayload($price)
            ),
            array_filter(
                $prices,
                static fn (mixed $value): bool => (
                    $value instanceof VariantPrice
                    && $value->priceList()->isActive()
                    && $value->variant()->isActive()
                    && $value->variant()->product()->isActive()
                ),
            ),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function rulePayloads(Tenant $tenant): array
    {
        $rules = $this->entityManager
            ->getRepository(PriceRule::class)
            ->findBy(['tenant' => $tenant, 'active' => true]);

        return array_values(array_map(
            static fn (PriceRule $rule): array => self::rulePayload($rule),
            array_filter(
                $rules,
                static fn (mixed $value): bool => (
                    $value instanceof PriceRule
                    && $value->priceList()->isActive()
                    && (
                        $value->commercialCategory() === null
                        || $value->commercialCategory()?->isActive()
                    )
                ),
            ),
        ));
    }

    private function priceList(Tenant $tenant, string $id): PriceList
    {
        $list = $this->entityManager
            ->getRepository(PriceList::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$list instanceof PriceList) {
            throw new NotFoundHttpException(
                'Lista de precios no encontrada.',
            );
        }

        return $list;
    }

    private function variant(
        Tenant $tenant,
        string $id,
    ): ProductVariant {
        $variant = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (
            !$variant instanceof ProductVariant
            || !$variant->product()->isActive()
        ) {
            throw new NotFoundHttpException('Variante no encontrada.');
        }

        return $variant;
    }

    private function category(
        Tenant $tenant,
        string $id,
    ): CommercialCategory {
        $category = $this->entityManager
            ->getRepository(CommercialCategory::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$category instanceof CommercialCategory) {
            throw new NotFoundHttpException(
                'Categoría comercial no encontrada.',
            );
        }

        return $category;
    }

    private function optionalCategory(
        Tenant $tenant,
        ?string $id,
    ): ?CommercialCategory {
        return $id === null ? null : $this->category($tenant, $id);
    }

    private function customer(Tenant $tenant, string $id): Customer
    {
        $customer = $this->entityManager
            ->getRepository(Customer::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$customer instanceof Customer) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        return $customer;
    }

    /** @param array<string, mixed> $payload */
    private function optionalDate(
        array $payload,
        string $field,
    ): ?DateTimeImmutable {
        $value = $this->commercialNullableString($payload, $field);
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (
            preg_match(
                '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}'
                .'(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D',
                $value,
            ) !== 1
        ) {
            throw new UnprocessableEntityHttpException(
                sprintf(
                    'El campo %s debe usar una fecha ISO 8601 con zona horaria.',
                    $field,
                ),
            );
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s no contiene una fecha válida.', $field),
                $exception,
            );
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (
            $errors !== false
            && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
        ) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s no contiene una fecha válida.', $field),
            );
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function assignPreferredList(
        CommercialCategory $category,
        ?PriceList $list,
    ): null {
        $category->assignPreferredPriceList($list);

        return null;
    }

    /** @return array<string, mixed> */
    private static function priceListPayload(PriceList $list): array
    {
        return [
            'id' => $list->id(),
            'name' => $list->name(),
            'slug' => $list->slug(),
            'currency' => $list->currency(),
        ];
    }

    /** @return array<string, mixed> */
    private static function variantPricePayload(
        VariantPrice $price,
    ): array {
        return [
            'id' => $price->id(),
            'price_list_id' => $price->priceList()->id(),
            'variant_id' => $price->variant()->id(),
            'amount_minor' => $price->amountMinor(),
        ];
    }

    /** @return array<string, mixed> */
    private static function rulePayload(PriceRule $rule): array
    {
        return [
            'id' => $rule->id(),
            'price_list_id' => $rule->priceList()->id(),
            'commercial_category_id' => $rule
                ->commercialCategory()?->id(),
            'name' => $rule->name(),
            'priority' => $rule->priority(),
            'discount_type' => $rule->discountType(),
            'discount_value' => $rule->discountValue(),
            'valid_from' => $rule->validFrom()?->format(DATE_ATOM),
            'valid_until' => $rule->validUntil()?->format(DATE_ATOM),
        ];
    }
}

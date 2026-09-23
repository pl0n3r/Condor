<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerController extends AbstractController
{
    use BranchApiSupport;
    use CommerceApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers',
        name: 'api_customers',
        methods: ['GET'],
    )]
    public function index(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope(
            $branchId,
            'customers.view',
        );

        $customers = $this->entityManager
            ->getRepository(Customer::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );
        $categories = $this->entityManager
            ->getRepository(CommercialCategory::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC'],
            );

        return $this->json([
            'customers' => array_values(array_map(
                static fn (Customer $customer): array => self::customerPayload(
                    $customer,
                ),
                array_filter(
                    $customers,
                    static fn (mixed $value): bool => $value instanceof Customer,
                ),
            )),
            'categories' => array_values(array_map(
                static fn (CommercialCategory $category): array => (
                    self::categoryPayload($category)
                ),
                array_filter(
                    $categories,
                    static fn (mixed $value): bool => (
                        $value instanceof CommercialCategory
                    ),
                ),
            )),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers/categories',
        name: 'api_customer_categories_create',
        methods: ['POST'],
    )]
    public function createCategory(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'customers.create',
        );
        $payload = $this->payload($request, ['name', 'slug']);
        $name = $this->commercialRequiredString($payload, 'name');
        $slug = $this->commercialRequiredString($payload, 'slug');
        $inactive = $this->entityManager
            ->getRepository(CommercialCategory::class)
            ->findOneBy([
                'tenant' => $tenant,
                'slug' => strtolower(trim($slug)),
                'active' => false,
            ]);
        $reactivated = $inactive instanceof CommercialCategory;
        $previousPreferredPriceListId = $reactivated
            ? $inactive->preferredPriceList()?->id()
            : null;

        $category = $this->domain(function () use (
            $inactive,
            $tenant,
            $name,
            $slug,
        ): CommercialCategory {
            if ($inactive instanceof CommercialCategory) {
                $preferredPriceList = $inactive->preferredPriceList();
                if (
                    $preferredPriceList !== null
                    && !$preferredPriceList->isActive()
                ) {
                    $inactive->assignPreferredPriceList(null);
                }
                $inactive->update($name, $slug);
                $inactive->activate();

                return $inactive;
            }

            return new CommercialCategory($tenant, $name, $slug);
        });
        $this->entityManager->persist($category);
        $this->audit(
            $tenant,
            $user,
            $reactivated
                ? 'commercial_category.reactivated'
                : 'commercial_category.created',
            CommercialCategory::class,
            $category->id(),
            [
                'branch_id' => $branch->id(),
                'previous_preferred_price_list_id' => $previousPreferredPriceListId,
                'preferred_price_list_id' => $category
                    ->preferredPriceList()?->id(),
            ],
        );
        $this->commercialFlushUnique(
            'Ya existe una categoría comercial con ese slug.',
        );

        return $this->json(
            ['category' => self::categoryPayload($category)],
            $reactivated ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers/categories/{categoryId}',
        name: 'api_customer_categories_update',
        methods: ['PATCH'],
    )]
    public function updateCategory(
        string $branchId,
        string $categoryId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'customers.update',
        );
        $category = $this->category($tenant, $categoryId);
        $payload = $this->payload($request, ['name', 'slug']);

        $this->domain(function () use ($category, $payload): void {
            $category->update(
                array_key_exists('name', $payload)
                    ? $this->commercialRequiredString($payload, 'name')
                    : $category->name(),
                array_key_exists('slug', $payload)
                    ? $this->commercialRequiredString($payload, 'slug')
                    : $category->slug(),
            );
        });
        $this->audit(
            $tenant,
            $user,
            'commercial_category.updated',
            CommercialCategory::class,
            $category->id(),
            ['branch_id' => $branch->id()],
        );
        $this->commercialFlushUnique(
            'Ya existe una categoría comercial con ese slug.',
        );

        return $this->json([
            'category' => self::categoryPayload($category),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers/categories/{categoryId}',
        name: 'api_customer_categories_delete',
        methods: ['DELETE'],
    )]
    public function deleteCategory(
        string $branchId,
        string $categoryId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'customers.delete',
        );
        $category = $this->category($tenant, $categoryId);
        $assigned = $this->entityManager
            ->getRepository(Customer::class)
            ->findOneBy([
                'tenant' => $tenant,
                'commercialCategory' => $category,
                'active' => true,
            ]);
        if ($assigned instanceof Customer) {
            throw new ConflictHttpException(
                'No puedes desactivar una categoría asignada a clientes activos.',
            );
        }

        $category->deactivate();
        $this->audit(
            $tenant,
            $user,
            'commercial_category.deactivated',
            CommercialCategory::class,
            $category->id(),
            ['branch_id' => $branch->id()],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers',
        name: 'api_customers_create',
        methods: ['POST'],
    )]
    public function createCustomer(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'customers.create',
        );
        $payload = $this->payload(
            $request,
            ['name', 'email', 'phone', 'notes', 'category_id'],
        );
        $category = $this->optionalCategory(
            $tenant,
            $this->commercialNullableString($payload, 'category_id'),
        );

        $customer = $this->domain(
            fn (): Customer => new Customer(
                $tenant,
                $this->commercialRequiredString($payload, 'name'),
                $this->commercialNullableString($payload, 'email'),
                $this->commercialNullableString($payload, 'phone'),
                $this->commercialNullableString($payload, 'notes'),
                $category,
            ),
        );
        $this->entityManager->persist($customer);
        $this->audit(
            $tenant,
            $user,
            'customer.created',
            Customer::class,
            $customer->id(),
            [
                'branch_id' => $branch->id(),
                'commercial_category_id' => $category?->id(),
            ],
        );
        $this->entityManager->flush();

        return $this->json(
            ['customer' => self::customerPayload($customer)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers/{customerId}',
        name: 'api_customers_update',
        methods: ['PATCH'],
    )]
    public function updateCustomer(
        string $branchId,
        string $customerId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'customers.update',
        );
        $customer = $this->customer($tenant, $customerId);
        $payload = $this->payload(
            $request,
            ['name', 'email', 'phone', 'notes', 'category_id'],
        );

        $category = array_key_exists('category_id', $payload)
            ? $this->optionalCategory(
                $tenant,
                $this->commercialNullableString($payload, 'category_id'),
            )
            : $customer->commercialCategory();
        $previousCategoryId = $customer->commercialCategory()?->id();

        $this->domain(function () use (
            $customer,
            $payload,
            $category,
        ): void {
            $customer->update(
                array_key_exists('name', $payload)
                    ? $this->commercialRequiredString($payload, 'name')
                    : $customer->name(),
                array_key_exists('email', $payload)
                    ? $this->commercialNullableString($payload, 'email')
                    : $customer->email(),
                array_key_exists('phone', $payload)
                    ? $this->commercialNullableString($payload, 'phone')
                    : $customer->phone(),
                array_key_exists('notes', $payload)
                    ? $this->commercialNullableString($payload, 'notes')
                    : $customer->notes(),
            );
            $customer->assignCommercialCategory($category);
        });
        $this->audit(
            $tenant,
            $user,
            'customer.updated',
            Customer::class,
            $customer->id(),
            [
                'branch_id' => $branch->id(),
                'previous_commercial_category_id' => $previousCategoryId,
                'commercial_category_id' => $category?->id(),
            ],
        );
        $this->entityManager->flush();

        return $this->json([
            'customer' => self::customerPayload($customer),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers/{customerId}',
        name: 'api_customers_delete',
        methods: ['DELETE'],
    )]
    public function deleteCustomer(
        string $branchId,
        string $customerId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'customers.delete',
        );
        $customer = $this->customer($tenant, $customerId);
        $customer->deactivate();
        $this->audit(
            $tenant,
            $user,
            'customer.deactivated',
            Customer::class,
            $customer->id(),
            ['branch_id' => $branch->id()],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
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

    /** @return array<string, mixed> */
    private static function customerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id(),
            'name' => $customer->name(),
            'email' => $customer->email(),
            'phone' => $customer->phone(),
            'notes' => $customer->notes(),
            'commercial_category_id' => $customer
                ->commercialCategory()?->id(),
        ];
    }

    /** @return array<string, mixed> */
    private static function categoryPayload(
        CommercialCategory $category,
    ): array {
        return [
            'id' => $category->id(),
            'name' => $category->name(),
            'slug' => $category->slug(),
            'preferred_price_list_id' => $category
                ->preferredPriceList()?->id(),
        ];
    }
}

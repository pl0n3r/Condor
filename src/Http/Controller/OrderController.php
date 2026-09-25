<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Orders\OrderConflictException;
use App\Application\Orders\OrderService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryReservation;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderLine;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    use BranchApiSupport;
    use CommerceApiSupport;
    use OrderItemsApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderService $orders,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders',
        name: 'api_orders',
        methods: ['GET'],
    )]
    public function index(string $branchId): JsonResponse
    {
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'orders.view',
        );
        $legalEntity = $this->legalEntity($branch);
        $sources = $this->accessibleSources(
            $tenant,
            $legalEntity,
            $user,
            $membership,
        );

        $orders = $sources === []
            ? []
            : array_values(array_filter(
                $this->entityManager->getRepository(Order::class)->findBy(
                    [
                        'tenant' => $tenant,
                        'legalEntity' => $legalEntity,
                        'inventorySource' => $sources,
                    ],
                    ['createdAt' => 'DESC'],
                    50,
                ),
                static fn (mixed $order): bool => $order instanceof Order,
            ));

        return $this->json([
            'orders' => array_map(
                fn (Order $order): array => $this->orderPayload($order),
                $orders,
            ),
            'sources' => array_map(
                static fn (InventorySource $source): array => [
                    'id' => $source->id(),
                    'name' => $source->name(),
                    'legal_entity_id' => $source->legalEntity()->id(),
                ],
                $sources,
            ),
            'price_lists' => array_map(
                static fn (PriceList $list): array => [
                    'id' => $list->id(),
                    'name' => $list->name(),
                    'currency' => $list->currency(),
                ],
                array_values(array_filter(
                    $this->entityManager
                        ->getRepository(PriceList::class)
                        ->findBy(
                            ['tenant' => $tenant, 'active' => true],
                            ['name' => 'ASC'],
                        ),
                    static fn (mixed $list): bool => $list instanceof PriceList,
                )),
            ),
            'customers' => array_map(
                static fn (Customer $customer): array => [
                    'id' => $customer->id(),
                    'name' => $customer->name(),
                ],
                array_values(array_filter(
                    $this->entityManager
                        ->getRepository(Customer::class)
                        ->findBy(
                            ['tenant' => $tenant, 'active' => true],
                            ['name' => 'ASC'],
                        ),
                    static fn (mixed $customer): bool => $customer instanceof Customer,
                )),
            ),
            'channels' => array_map(
                static fn (SalesChannel $channel): array => [
                    'id' => $channel->id(),
                    'name' => $channel->name(),
                    'inventory_source_id' => $channel->inventorySource()->id(),
                    'price_list_id' => $channel->priceList()->id(),
                ],
                array_values(array_filter(
                    $this->entityManager
                        ->getRepository(SalesChannel::class)
                        ->findBy([
                            'tenant' => $tenant,
                            'legalEntity' => $legalEntity,
                            'active' => true,
                        ]),
                    static fn (mixed $channel): bool => (
                        $channel instanceof SalesChannel
                        && $channel->isPublishable()
                    ),
                )),
            ),
            'variants' => array_map(
                static fn (ProductVariant $variant): array => [
                    'id' => $variant->id(),
                    'sku' => $variant->sku(),
                    'name' => $variant->name(),
                    'product_name' => $variant->product()->name(),
                ],
                array_values(array_filter(
                    $this->entityManager
                        ->getRepository(ProductVariant::class)
                        ->findBy(
                            ['tenant' => $tenant, 'active' => true],
                            ['name' => 'ASC'],
                        ),
                    static fn (mixed $variant): bool => (
                        $variant instanceof ProductVariant
                        && $variant->product()->isActive()
                    ),
                )),
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders',
        name: 'api_orders_create',
        methods: ['POST'],
    )]
    public function create(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'orders.create',
        );
        $legalEntity = $this->legalEntity($branch);
        $payload = $this->payload(
            $request,
            [
                'inventory_source_id',
                'price_list_id',
                'sales_channel_id',
                'customer_id',
                'idempotency_key',
                'items',
            ],
        );

        $source = $this->source(
            $tenant,
            $legalEntity,
            $user,
            $membership,
            $this->commercialRequiredString(
                $payload,
                'inventory_source_id',
            ),
        );
        $priceList = $this->priceList(
            $tenant,
            $this->commercialRequiredString($payload, 'price_list_id'),
        );
        $channel = $this->optionalChannel(
            $tenant,
            $legalEntity,
            $this->commercialNullableString($payload, 'sales_channel_id'),
        );
        $customer = $this->optionalCustomer(
            $tenant,
            $this->commercialNullableString($payload, 'customer_id'),
        );
        $items = $this->orderItems(
            $this->entityManager,
            $tenant,
            $payload['items'] ?? null,
            'pedido',
        );

        $created = false;
        try {
            $order = $this->domain(function () use (
                &$created,
                $tenant,
                $source,
                $priceList,
                $channel,
                $customer,
                $user,
                $payload,
                $items,
            ): Order {
                return $this->orders->create(
                    $tenant,
                    $source,
                    $priceList,
                    $channel,
                    $customer,
                    $user->id(),
                    $this->commercialRequiredString(
                        $payload,
                        'idempotency_key',
                    ),
                    $items,
                    OrderService::ORIGIN_MANUAL,
                    static function () use (&$created): void {
                        $created = true;
                    },
                );
            });
        } catch (OrderConflictException $exception) {
            throw new ConflictHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        return $this->json(
            ['order' => $this->orderPayload($order)],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders/{orderId}/cancel',
        name: 'api_orders_cancel',
        methods: ['POST'],
    )]
    public function cancel(
        string $branchId,
        string $orderId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'orders.update',
        );
        $order = $this->order(
            $tenant,
            $this->legalEntity($branch),
            $user,
            $membership,
            $orderId,
        );

        $order = $this->domain(
            fn (): Order => $this->orders->cancel(
                $order,
                $user->id(),
            ),
        );

        return $this->json(['order' => $this->orderPayload($order)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/orders/{orderId}/consume',
        name: 'api_orders_consume',
        methods: ['POST'],
    )]
    public function consume(
        string $branchId,
        string $orderId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'orders.update',
        );
        $order = $this->order(
            $tenant,
            $this->legalEntity($branch),
            $user,
            $membership,
            $orderId,
        );

        $order = $this->domain(
            fn (): Order => $this->orders->consume(
                $order,
                $user->id(),
            ),
        );

        return $this->json(['order' => $this->orderPayload($order)]);
    }

    private function order(
        Tenant $tenant,
        LegalEntity $legalEntity,
        User $user,
        Membership $membership,
        string $id,
    ): Order {
        $sources = $this->accessibleSources(
            $tenant,
            $legalEntity,
            $user,
            $membership,
        );
        if ($sources === []) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }

        $order = $this->entityManager
            ->getRepository(Order::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'legalEntity' => $legalEntity,
                'inventorySource' => $sources,
            ]);
        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }

        return $order;
    }

    private function legalEntity(Branch $branch): LegalEntity
    {
        $legalEntity = $branch->legalEntity();
        if (!$legalEntity instanceof LegalEntity) {
            throw new ConflictHttpException(
                'La sede debe tener una entidad legal antes de operar pedidos.',
            );
        }

        return $legalEntity;
    }

    /**
     * @return list<InventorySource>
     */
    private function accessibleSources(
        Tenant $tenant,
        LegalEntity $legalEntity,
        User $user,
        Membership $membership,
    ): array {
        $owner = $this->authorization->isOwner($membership);
        $sources = $this->entityManager
            ->getRepository(InventorySource::class)
            ->findBy(
                [
                    'tenant' => $tenant,
                    'legalEntity' => $legalEntity,
                    'active' => true,
                ],
                ['name' => 'ASC'],
            );

        return array_values(array_filter(
            $sources,
            function (mixed $source) use (
                $owner,
                $user,
                $tenant,
            ): bool {
                if (!$source instanceof InventorySource) {
                    return false;
                }
                if ($owner) {
                    return true;
                }

                $sourceBranch = $source->branch();

                return $sourceBranch instanceof Branch
                    && $this->authorization->canAccessBranch(
                        $user,
                        $tenant,
                        $sourceBranch,
                    );
            },
        ));
    }

    private function source(
        Tenant $tenant,
        LegalEntity $legalEntity,
        User $user,
        Membership $membership,
        string $sourceId,
    ): InventorySource {
        foreach (
            $this->accessibleSources(
                $tenant,
                $legalEntity,
                $user,
                $membership,
            ) as $source
        ) {
            if ($source->id() === $sourceId) {
                return $source;
            }
        }

        throw new AccessDeniedHttpException(
            'No tienes acceso a la fuente de inventario solicitada.',
        );
    }

    private function priceList(
        Tenant $tenant,
        string $id,
    ): PriceList {
        $priceList = $this->entityManager
            ->getRepository(PriceList::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$priceList instanceof PriceList) {
            throw new NotFoundHttpException(
                'Lista de precios no encontrada.',
            );
        }

        return $priceList;
    }

    private function optionalChannel(
        Tenant $tenant,
        LegalEntity $legalEntity,
        ?string $id,
    ): ?SalesChannel {
        if ($id === null || trim($id) === '') {
            return null;
        }

        $channel = $this->entityManager
            ->getRepository(SalesChannel::class)
            ->findOneBy([
                'id' => $id,
                'tenant' => $tenant,
                'legalEntity' => $legalEntity,
                'active' => true,
            ]);
        if (
            !$channel instanceof SalesChannel
            || !$channel->isPublishable()
        ) {
            throw new NotFoundHttpException(
                'Canal de ventas no encontrado.',
            );
        }

        return $channel;
    }

    private function optionalCustomer(
        Tenant $tenant,
        ?string $id,
    ): ?Customer {
        if ($id === null || trim($id) === '') {
            return null;
        }

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

    /** @return array<string, mixed> */
    private function orderPayload(Order $order): array
    {
        $lines = array_values(array_filter(
            $this->entityManager
                ->getRepository(OrderLine::class)
                ->findBy(['order' => $order]),
            static fn (mixed $line): bool => $line instanceof OrderLine,
        ));
        $reservations = array_values(array_filter(
            $this->entityManager
                ->getRepository(InventoryReservation::class)
                ->findBy(['order' => $order]),
            static fn (mixed $reservation): bool => (
                $reservation instanceof InventoryReservation
            ),
        ));
        $reservationByLine = [];
        foreach ($reservations as $reservation) {
            $reservationByLine[$reservation->orderLine()->id()] = $reservation;
        }

        return [
            'id' => $order->id(),
            'legal_entity_id' => $order->legalEntity()->id(),
            'inventory_source_id' => $order->inventorySource()->id(),
            'sales_channel_id' => $order->salesChannel()?->id(),
            'customer_id' => $order->customer()?->id(),
            'order_status' => $order->orderStatus(),
            'payment_status' => $order->paymentStatus(),
            'fulfillment_status' => $order->fulfillmentStatus(),
            'currency' => $order->currency(),
            'total_amount_minor' => $order->totalAmountMinor(),
            'created_at' => $order->createdAt()->format(DATE_ATOM),
            'lines' => array_map(
                static function (OrderLine $line) use (
                    $reservationByLine,
                ): array {
                    $reservation = $reservationByLine[$line->id()] ?? null;

                    return [
                        'id' => $line->id(),
                        'variant_id' => $line->variant()->id(),
                        'quantity' => $line->quantity(),
                        'base_amount_minor' => $line->baseAmountMinor(),
                        'effective_amount_minor' => $line->effectiveAmountMinor(),
                        'line_total_minor' => $line->lineTotalMinor(),
                        'currency' => $line->currency(),
                        'price_list_id' => $line->priceList()->id(),
                        'price_rule_id' => $line->priceRuleId(),
                        'reservation_status' => $reservation instanceof InventoryReservation
                            ? $reservation->status()
                            : null,
                    ];
                },
                $lines,
            ),
        ];
    }
}

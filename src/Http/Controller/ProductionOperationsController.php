<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\BillOfMaterialsService;
use App\Application\Production\ProductionOrderService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\BillOfMaterialsLine;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProductionOperationsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly BillOfMaterialsService $billOfMaterials,
        private readonly ProductionOrderService $orders,
        private readonly SubscriptionEntitlementContextFactory $entitlementContexts,
        private readonly EntitlementResolver $entitlementResolver,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/boms',
        name: 'api_production_boms',
        methods: ['GET'],
    )]
    public function boms(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.view',
        );
        $this->productionEntitlements($tenant);
        $this->branchSource($tenant, $branch);

        $boms = $this->entityManager
            ->getRepository(BillOfMaterials::class)
            ->findBy(['tenant' => $tenant], ['createdAt' => 'DESC']);

        return $this->json([
            'branch' => ['id' => $branch->id(), 'name' => $branch->name()],
            'boms' => array_map(
                self::bomPayload(...),
                array_values(array_filter(
                    $boms,
                    static fn (mixed $bom): bool =>
                        $bom instanceof BillOfMaterials,
                )),
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/boms',
        name: 'api_production_bom_create',
        methods: ['POST'],
    )]
    public function createBom(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.create',
        );
        $entitlements = $this->productionEntitlements($tenant);
        $this->branchSource($tenant, $branch);
        $payload = $this->payload($request, ['variant_id', 'components']);

        $variant = $this->variant(
            $tenant,
            $this->requiredString($payload, 'variant_id', 26),
        );
        $components = $this->components($tenant, $payload['components'] ?? null);

        $bom = $this->domain(
            fn (): BillOfMaterials => $this->billOfMaterials->createVersion(
                $tenant,
                $entitlements,
                $variant,
                $components,
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'production_bom.created',
            BillOfMaterials::class,
            $bom->id(),
            [
                'branch_id' => $branch->id(),
                'variant_id' => $variant->id(),
                'version' => $bom->version(),
            ],
        );
        $this->entityManager->flush();

        return $this->json(
            ['bom' => self::bomPayload($bom)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/orders',
        name: 'api_production_orders',
        methods: ['GET'],
    )]
    public function orders(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.view',
        );
        $this->productionEntitlements($tenant);
        $source = $this->branchSource($tenant, $branch);

        $orders = $this->entityManager
            ->getRepository(ProductionOrder::class)
            ->findBy(
                [
                    'tenant' => $tenant,
                    'source' => $source,
                ],
                ['createdAt' => 'DESC'],
            );

        return $this->json([
            'branch' => ['id' => $branch->id(), 'name' => $branch->name()],
            'source' => [
                'id' => $source->id(),
                'name' => $source->name(),
            ],
            'orders' => array_map(
                self::orderPayload(...),
                array_values(array_filter(
                    $orders,
                    static fn (mixed $order): bool =>
                        $order instanceof ProductionOrder,
                )),
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/orders',
        name: 'api_production_order_create',
        methods: ['POST'],
    )]
    public function createOrder(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.create',
        );
        $entitlements = $this->productionEntitlements($tenant);
        $source = $this->branchSource($tenant, $branch);
        $payload = $this->payload($request, ['bom_id', 'target_quantity']);

        $bom = $this->bom(
            $tenant,
            $this->requiredString($payload, 'bom_id', 26),
        );
        $targetQuantity = $this->requiredPositiveInt(
            $payload,
            'target_quantity',
        );

        $order = $this->domain(
            fn (): ProductionOrder => $this->orders->create(
                $tenant,
                $entitlements,
                $bom,
                $bom->variant(),
                $source,
                $targetQuantity,
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'production_order.created',
            ProductionOrder::class,
            $order->id(),
            [
                'branch_id' => $branch->id(),
                'source_id' => $source->id(),
                'bom_id' => $bom->id(),
            ],
        );
        $this->entityManager->flush();

        return $this->json(
            ['order' => self::orderPayload($order)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/orders/{orderId}/complete',
        name: 'api_production_order_complete',
        methods: ['POST'],
    )]
    public function completeOrder(
        string $branchId,
        string $orderId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $entitlements = $this->productionEntitlements($tenant);
        $source = $this->branchSource($tenant, $branch);
        $order = $this->order($tenant, $source, $orderId);
        $payload = $this->payload(
            $request,
            ['completed_quantity', 'idempotency_key'],
        );
        $completedQuantity = $this->requiredPositiveInt(
            $payload,
            'completed_quantity',
        );
        $key = $this->requiredString($payload, 'idempotency_key', 80);
        $created = !$order->isCompleted();

        $completed = $this->domain(
            fn (): ProductionOrder => $this->orders->complete(
                $tenant,
                $entitlements,
                $order,
                $completedQuantity,
                $key,
                $user->id(),
            ),
        );

        if ($created) {
            $this->audit(
                $tenant,
                $user,
                'production_order.completed',
                ProductionOrder::class,
                $completed->id(),
                [
                    'branch_id' => $branch->id(),
                    'source_id' => $source->id(),
                    'completed_quantity' => $completedQuantity,
                ],
            );
            $this->entityManager->flush();
        }

        return $this->json(
            ['order' => self::orderPayload($completed)],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    private function productionEntitlements(Tenant $tenant): EntitlementSnapshot
    {
        return $this->domain(function () use ($tenant): EntitlementSnapshot {
            $context = $this->entitlementContexts->forTenant(
                $tenant->id(),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            $snapshot = $this->entitlementResolver->resolve($context);

            if ($snapshot->addOn('production-lite') !== true) {
                throw new DomainException(
                    'Producción Lite no está habilitada para este tenant.',
                );
            }

            return $snapshot;
        });
    }

    private function branchSource(
        Tenant $tenant,
        Branch $branch,
    ): InventorySource {
        $source = $this->entityManager
            ->getRepository(InventorySource::class)
            ->findOneBy([
                'tenant' => $tenant,
                'branch' => $branch,
                'type' => InventorySource::TYPE_BRANCH,
                'active' => true,
            ]);
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException(
                'Fuente de inventario activa para la sede no encontrada.',
            );
        }

        return $source;
    }

    private function variant(
        Tenant $tenant,
        string $variantId,
    ): ProductVariant {
        $variant = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findOneBy([
                'id' => $variantId,
                'tenant' => $tenant,
            ]);
        if (!$variant instanceof ProductVariant) {
            throw new NotFoundHttpException('Variante no encontrada.');
        }

        return $variant;
    }

    private function material(
        Tenant $tenant,
        string $materialId,
    ): Material {
        $material = $this->entityManager
            ->getRepository(Material::class)
            ->findOneBy([
                'id' => $materialId,
                'tenant' => $tenant,
            ]);
        if (!$material instanceof Material) {
            throw new NotFoundHttpException('Material no encontrado.');
        }

        return $material;
    }

    private function bom(Tenant $tenant, string $bomId): BillOfMaterials
    {
        $bom = $this->entityManager
            ->getRepository(BillOfMaterials::class)
            ->findOneBy([
                'id' => $bomId,
                'tenant' => $tenant,
            ]);
        if (!$bom instanceof BillOfMaterials) {
            throw new NotFoundHttpException('BOM no encontrada.');
        }

        return $bom;
    }

    private function order(
        Tenant $tenant,
        InventorySource $source,
        string $orderId,
    ): ProductionOrder {
        $order = $this->entityManager
            ->getRepository(ProductionOrder::class)
            ->findOneBy([
                'id' => $orderId,
                'tenant' => $tenant,
                'source' => $source,
            ]);
        if (!$order instanceof ProductionOrder) {
            throw new NotFoundHttpException('Orden de producción no encontrada.');
        }

        return $order;
    }

    /**
     * @param mixed $raw
     * @return list<array{
     *   material: Material,
     *   quantity: string,
     *   unit: UnitOfMeasure
     * }>
     */
    private function components(Tenant $tenant, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new UnprocessableEntityHttpException(
                'La BOM requiere una lista de componentes.',
            );
        }

        $components = [];
        foreach ($raw as $component) {
            if (is_object($component)) {
                $component = get_object_vars($component);
            }
            if (!is_array($component) || array_is_list($component)) {
                throw new UnprocessableEntityHttpException(
                    'Cada componente de BOM debe ser un objeto.',
                );
            }
            $unexpected = array_values(array_diff(
                array_keys($component),
                ['material_id', 'quantity', 'unit'],
            ));
            if ($unexpected !== []) {
                throw new UnprocessableEntityHttpException(
                    'El componente contiene campos no permitidos.',
                );
            }

            $material = $this->material(
                $tenant,
                $this->requiredString($component, 'material_id', 26),
            );
            $quantity = $this->requiredString($component, 'quantity', 64);
            $unit = UnitOfMeasure::from(
                $this->requiredString($component, 'unit', 16),
            );
            $components[] = [
                'material' => $material,
                'quantity' => $quantity,
                'unit' => $unit,
            ];
        }

        return $components;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(
        array $payload,
        string $field,
        int $maxLength,
    ): string {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                'El campo '.$field.' es obligatorio.',
            );
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new UnprocessableEntityHttpException(
                'El campo '.$field.' no es válido.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredPositiveInt(
        array $payload,
        string $field,
    ): int {
        $value = $payload[$field] ?? null;
        if (!is_int($value) || $value <= 0 || $value > 2147483647) {
            throw new UnprocessableEntityHttpException(
                'El campo '.$field.' debe ser un entero positivo válido.',
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function bomPayload(BillOfMaterials $bom): array
    {
        return [
            'id' => $bom->id(),
            'variant_id' => $bom->variant()->id(),
            'variant_sku' => $bom->variant()->sku(),
            'variant_name' => $bom->variant()->name(),
            'version' => $bom->version(),
            'active' => $bom->isActive(),
            'lines' => array_map(
                self::bomLinePayload(...),
                $bom->lines(),
            ),
            'created_at' => $bom->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    private static function bomLinePayload(BillOfMaterialsLine $line): array
    {
        return [
            'material_id' => $line->material()->id(),
            'material_code' => $line->material()->code(),
            'material_name' => $line->material()->name(),
            'quantity' => $line->quantity(),
            'unit' => $line->unitOfMeasure()->key(),
        ];
    }

    /** @return array<string,mixed> */
    private static function orderPayload(ProductionOrder $order): array
    {
        return [
            'id' => $order->id(),
            'bom_id' => $order->billOfMaterials()->id(),
            'bom_version' => $order->billOfMaterials()->version(),
            'variant_id' => $order->variant()->id(),
            'variant_sku' => $order->variant()->sku(),
            'source_id' => $order->source()->id(),
            'target_quantity' => $order->targetQuantity(),
            'completed_quantity' => $order->completedQuantity(),
            'status' => $order->status(),
            'completion_idempotency_key' =>
                $order->completionIdempotencyKey(),
            'created_at' => $order->createdAt()->format(DATE_ATOM),
            'completed_at' => $order->completedAt()?->format(DATE_ATOM),
        ];
    }
}

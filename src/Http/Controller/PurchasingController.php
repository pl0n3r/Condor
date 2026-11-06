<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Purchasing\MaterialCostService;
use App\Application\Purchasing\PurchaseOrderService;
use App\Application\Purchasing\PurchaseRequestPreview;
use App\Application\Purchasing\QuotationPreview;
use App\Application\Purchasing\SupplierEvaluationPreview;
use App\Application\Purchasing\SupplierContractPreview;
use App\Application\Purchasing\SupplierService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Branch-scoped purchasing API. The server resolves tenant, legal entity and source. */
final class PurchasingController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SupplierService $suppliers,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly MaterialCostService $materialCosts,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/purchasing/suppliers', name: 'api_purchasing_suppliers', methods: ['GET'])]
    public function suppliers(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $suppliers = $this->entityManager->getRepository(Supplier::class)
            ->findBy(['tenant' => $tenant], ['code' => 'ASC']);

        return $this->json(['suppliers' => array_map(self::supplierPayload(...), $suppliers)]);
    }

    #[Route('/api/v1/branches/{branchId}/purchasing/suppliers', name: 'api_purchasing_supplier_create', methods: ['POST'])]
    public function createSupplier(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.create');
        $payload = $this->payload($request, ['code', 'name', 'payment_terms_days']);
        $days = array_key_exists('payment_terms_days', $payload)
            ? $payload['payment_terms_days']
            : 0;
        if (!is_int($days) || $days < 0 || $days > 365) {
            throw new UnprocessableEntityHttpException('El plazo de pago debe estar entre 0 y 365 días.');
        }

        $supplier = $this->domain(fn (): Supplier => $this->suppliers->create(
            $tenant,
            $this->text($payload, 'code', 64),
            $this->text($payload, 'name', 160),
            $days,
        ));
        $this->audit($tenant, $user, 'purchasing_supplier.created', Supplier::class, $supplier->id(), [
            'branch_id' => $branch->id(),
        ]);
        $this->flushUnique('Ya existe un proveedor con ese código en el tenant.');

        return $this->json(['supplier' => self::supplierPayload($supplier)], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/purchasing/orders', name: 'api_purchasing_orders', methods: ['GET'])]
    public function orders(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $source = $this->branchSource($tenant, $branch, false);
        $orders = $this->entityManager->getRepository(PurchaseOrder::class)->findBy(
            ['tenant' => $tenant, 'legalEntity' => $source->legalEntity()],
            ['createdAt' => 'DESC'],
        );

        return $this->json(['orders' => array_map(self::orderPayload(...), $orders)]);
    }

    /** Read-only historical average from all received purchases of the authorized tenant. */
    #[Route('/api/v1/branches/{branchId}/purchasing/material-costs', name: 'api_purchasing_material_costs', methods: ['GET'])]
    public function materialCosts(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $this->branchSource($tenant, $branch, false);

        $materials = $this->entityManager->getRepository(Material::class)->findBy(
            ['tenant' => $tenant], ['code' => 'ASC'],
        );
        $orders = $this->entityManager->getRepository(PurchaseOrder::class)->findBy([
            'tenant' => $tenant,
            'status' => PurchaseOrder::STATUS_RECEIVED,
        ]);
        $costs = [];
        foreach ($materials as $material) {
            $costs[] = array_merge(
                ['material_id' => $material->id()],
                $this->domain(fn (): array => $this->materialCosts->calculate($tenant, $material, $orders)),
            );
        }

        return $this->json(['material_costs' => $costs]);
    }

    #[Route('/api/v1/branches/{branchId}/purchasing/orders', name: 'api_purchasing_order_create', methods: ['POST'])]
    public function createOrder(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.create');
        $source = $this->branchSource($tenant, $branch);
        $payload = $this->payload($request, ['supplier_id', 'lines']);
        $supplier = $this->supplier($tenant, $this->text($payload, 'supplier_id', 26));
        $lines = $this->orderLines($tenant, $payload['lines'] ?? null);
        $order = $this->domain(fn (): PurchaseOrder => $this->purchaseOrders->create(
            $tenant,
            $source->legalEntity(),
            $supplier,
            $lines,
        ));
        $this->audit($tenant, $user, 'purchasing_order.created', PurchaseOrder::class, $order->id(), [
            'branch_id' => $branch->id(),
            'supplier_id' => $supplier->id(),
        ]);
        $this->flushUnique('La orden contiene registros duplicados.');

        return $this->json(['order' => self::orderPayload($order)], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/purchasing/orders/{orderId}/receive', name: 'api_purchasing_order_receive', methods: ['POST'])]
    public function receiveOrder(string $branchId, string $orderId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.update');
        $source = $this->branchSource($tenant, $branch, false);
        $order = $this->order($tenant, $source, $orderId);
        $payload = $this->payload($request, ['idempotency_key']);
        $key = $this->text($payload, 'idempotency_key', 80);
        // Lock first, then decide whether this request created a receipt.
        // The outer transaction includes both the stock update and its audit event.
        // A concurrent retry therefore cannot record a second receipt audit.
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use (
                $tenant, $source, $order, $key, $user, $branch
            ): JsonResponse {
                $entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
                $alreadyReceived = $order->isReceived();
                if (!$alreadyReceived && !$source->isActive()) {
                    throw new NotFoundHttpException('Fuente de inventario activa para la sede no encontrada.');
                }
                $received = $this->domain(fn (): PurchaseOrder => $this->purchaseOrders->receive(
                    $tenant, $source, $order, $key, $user->id(),
                ));
                if (!$alreadyReceived) {
                    $this->audit($tenant, $user, 'purchasing_order.received', PurchaseOrder::class, $received->id(), [
                        'branch_id' => $branch->id(),
                        'source_id' => $source->id(),
                    ]);
                }

                return $this->json(
                    ['order' => self::orderPayload($received)],
                    $alreadyReceived ? Response::HTTP_OK : Response::HTTP_CREATED,
                );
            },
        );
    }


    /**
     * Synthetic-only quotation: no supplier contact, orders, persistence or money movement.
     */
    #[Route('/api/v1/branches/{branchId}/purchasing/quotations/preview', name: 'api_purchasing_quotation_preview', methods: ['POST'])]
    public function previewQuotation(string $branchId, Request $request): JsonResponse
    {
        $this->requirePurchasingPreviewEnabled();
        $this->requireCsrf($request);
        $this->authorizedBranchScope($branchId, 'inventory.create');

        try {
            $preview = QuotationPreview::preview($this->syntheticPurchasingPayload($request));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $this->json(['quotation_preview' => $preview, 'synthetic_only' => true]);
    }

    /**
     * Synthetic-only purchase request: never approves, places or pays for an order.
     */
    #[Route('/api/v1/branches/{branchId}/purchasing/requests/preview', name: 'api_purchasing_request_preview', methods: ['POST'])]
    public function previewRequest(string $branchId, Request $request): JsonResponse
    {
        $this->requirePurchasingPreviewEnabled();
        $this->requireCsrf($request);
        $this->authorizedBranchScope($branchId, 'inventory.create');

        try {
            $preview = PurchaseRequestPreview::preview($this->syntheticPurchasingPayload($request));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $this->json(['request_preview' => $preview, 'synthetic_only' => true]);
    }

    /**
     * Construction-only supplier evaluation; no supplier lookup, award or persistence.
     */
    #[Route('/api/v1/branches/{branchId}/purchasing/suppliers/evaluations/preview', name: 'api_purchasing_supplier_evaluation_preview', methods: ['POST'])]
    public function previewSupplierEvaluation(string $branchId, Request $request): JsonResponse
    {
        $this->requirePurchasingPreviewEnabled();
        $this->requireCsrf($request);
        $this->authorizedBranchScope($branchId, 'inventory.create');

        try {
            $preview = SupplierEvaluationPreview::preview($this->syntheticPurchasingPayload($request));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $this->json(['supplier_evaluation_preview' => $preview, 'synthetic_only' => true]);
    }

    /**
     * Synthetic contract metadata only: no signatures, legal validity or persistence.
     */
    #[Route('/api/v1/branches/{branchId}/purchasing/suppliers/contracts/preview', name: 'api_purchasing_supplier_contract_preview', methods: ['POST'])]
    public function previewSupplierContract(string $branchId, Request $request): JsonResponse
    {
        $this->requirePurchasingPreviewEnabled();
        $this->requireCsrf($request);
        $this->authorizedBranchScope($branchId, 'inventory.create');

        try {
            $preview = SupplierContractPreview::preview($this->syntheticPurchasingPayload($request));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $this->json(['supplier_contract_preview' => $preview, 'synthetic_only' => true]);
    }

    private function requirePurchasingPreviewEnabled(): void
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment === 'test') {
            return;
        }
        if ($environment !== 'dev'
            || getenv('CONDOR_PURCHASING_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        ) {
            throw new NotFoundHttpException('Compras sintéticas no disponibles.');
        }
    }

    /** @return array<string,mixed> */
    private function syntheticPurchasingPayload(Request $request): array
    {
        if (strlen($request->getContent()) > 65536) {
            throw new BadRequestHttpException('Fixture sintético demasiado grande.');
        }
        try {
            $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new BadRequestHttpException('Se requiere un objeto JSON.');
        }
        // Fixed fixture scope is a sentinel, not a customer-supplied tenant identity.
        // Real branch membership and RBAC were checked before parsing this payload.
        foreach ([
            'tenant_ref' => 'fixture:tenant/current',
            'legal_entity_ref' => 'fixture:legal-entity/current',
            'site_ref' => 'fixture:site/current',
        ] as $field => $expected) {
            if (($payload[$field] ?? null) !== $expected) {
                throw new UnprocessableEntityHttpException('Ámbito sintético inválido.');
            }
        }
        return $payload;
    }

    private function branchSource(Tenant $tenant, Branch $branch, bool $requireActive = true): InventorySource
    {
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'branch' => $branch,
            'type' => InventorySource::TYPE_BRANCH,
        ]);
        if ($source instanceof InventorySource && $requireActive && !$source->isActive()) {
            throw new NotFoundHttpException('Fuente de inventario activa para la sede no encontrada.');
        }
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException('Fuente de inventario activa para la sede no encontrada.');
        }
        return $source;
    }

    private function supplier(Tenant $tenant, string $id): Supplier
    {
        $supplier = $this->entityManager->getRepository(Supplier::class)->findOneBy([
            'id' => $id, 'tenant' => $tenant,
        ]);
        if (!$supplier instanceof Supplier || !$supplier->isActive()) {
            throw new NotFoundHttpException('Proveedor activo no encontrado.');
        }
        return $supplier;
    }

    private function order(Tenant $tenant, InventorySource $source, string $id): PurchaseOrder
    {
        $order = $this->entityManager->getRepository(PurchaseOrder::class)->findOneBy([
            'id' => $id,
            'tenant' => $tenant,
            'legalEntity' => $source->legalEntity(),
        ]);
        if (!$order instanceof PurchaseOrder) {
            throw new NotFoundHttpException('Orden de compra no encontrada.');
        }
        return $order;
    }

    /** @return list<array{material: Material, quantity: string, unitCost: string}> */
    private function orderLines(Tenant $tenant, mixed $submitted): array
    {
        if (!is_array($submitted) || !array_is_list($submitted) || $submitted === [] || count($submitted) > 100) {
            throw new UnprocessableEntityHttpException('Las líneas deben ser una lista de 1 a 100 elementos.');
        }
        $lines = [];
        foreach ($submitted as $line) {
            if (!is_object($line)) {
                throw new UnprocessableEntityHttpException('Cada línea debe ser un objeto JSON.');
            }
            $data = get_object_vars($line);
            if (array_diff(array_keys($data), ['material_id', 'quantity', 'unit_cost']) !== []) {
                throw new UnprocessableEntityHttpException('La línea contiene campos no permitidos.');
            }
            $materialId = $this->text($data, 'material_id', 26);
            $material = $this->entityManager->getRepository(Material::class)->findOneBy([
                'id' => $materialId, 'tenant' => $tenant, 'active' => true,
            ]);
            if (!$material instanceof Material) {
                throw new NotFoundHttpException('Material no encontrado.');
            }
            $lines[] = [
                'material' => $material,
                'quantity' => $this->text($data, 'quantity', 40),
                'unitCost' => $this->text($data, 'unit_cost', 40),
            ];
        }
        return $lines;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $field, int $maxLength): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('El campo '.$field.' no es válido.');
        }
        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');
        if ($value === '' || $length === false || $length > $maxLength
            || preg_match('/[\\x00-\\x1F\\x7F]/', $value) === 1
        ) {
            throw new UnprocessableEntityHttpException('El campo '.$field.' no es válido.');
        }
        return $value;
    }

    private function flushUnique(string $message): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException($message, $exception);
        }
    }

    /** @return array<string, mixed> */
    private static function supplierPayload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id(), 'code' => $supplier->code(), 'name' => $supplier->name(),
            'payment_terms_days' => $supplier->paymentTermsDays(), 'active' => $supplier->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private static function orderPayload(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id(), 'status' => $order->status(),
            'supplier_id' => $order->supplier()->id(),
            'legal_entity_id' => $order->legalEntity()->id(),
            'receipt_source_id' => $order->receiptSourceId(),
            'lines' => array_map(static fn ($line): array => [
                'id' => $line->id(),
                'material_id' => $line->material()->id(),
                'quantity' => $line->quantity(),
                'unit_cost' => $line->unitCost(),
            ], $order->lines()),
        ];
    }
}

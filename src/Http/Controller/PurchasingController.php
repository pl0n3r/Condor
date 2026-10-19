<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Purchasing\PurchaseOrderService;
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
        $days = $payload['payment_terms_days'] ?? 0;
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
        $source = $this->branchSource($tenant, $branch);
        $orders = $this->entityManager->getRepository(PurchaseOrder::class)->findBy(
            ['tenant' => $tenant, 'legalEntity' => $source->legalEntity()],
            ['createdAt' => 'DESC'],
        );

        return $this->json(['orders' => array_map(self::orderPayload(...), $orders)]);
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
        $source = $this->branchSource($tenant, $branch);
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

    private function branchSource(Tenant $tenant, Branch $branch): InventorySource
    {
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'branch' => $branch,
            'type' => InventorySource::TYPE_BRANCH,
            'active' => true,
        ]);
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

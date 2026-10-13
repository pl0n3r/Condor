<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use App\Application\Production\MaterialInventoryService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PurchaseOrderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MaterialInventoryService $materialInventory,
    ) {
    }

    /** @param list<array{material:Material,quantity:string,unitCost:string}> $items */
    public function create(Tenant $tenant, \App\Domain\Purchasing\Entity\Supplier $supplier, array $items): PurchaseOrder
    {
        if ($items === []) {
            throw new DomainException('La orden de compra debe incluir al menos una línea.');
        }
        $order = new PurchaseOrder($tenant, $supplier);
        foreach ($items as $item) {
            if (!isset($item['material'], $item['quantity'], $item['unitCost'])
                || !$item['material'] instanceof Material
                || !is_string($item['quantity'])
                || !is_string($item['unitCost'])
            ) {
                throw new DomainException('Cada línea requiere material, cantidad y costo unitario.');
            }
            $order->addLine($item['material'], $item['quantity'], $item['unitCost']);
        }
        $this->entityManager->persist($order);

        return $order;
    }

    public function receive(
        Tenant $tenant,
        InventorySource $source,
        PurchaseOrder $order,
        string $idempotencyKey,
        ?string $actorUserId = null,
    ): PurchaseOrder {
        $key = PurchaseOrder::normalizeReceiptKey($idempotencyKey);
        self::assertScope($tenant, $source, $order);

        try {
            return $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use (
                    $tenant, $source, $order, $key, $actorUserId
                ): PurchaseOrder {
                    $entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
                    self::assertScope($tenant, $source, $order);
                    if ($order->isReceived()) {
                        $order->assertSameReceipt($key, $source->id());
                        return $order;
                    }
                    $other = $entityManager->getRepository(PurchaseOrder::class)->findOneBy([
                        'tenant' => $tenant,
                        'receiptKey' => $key,
                    ]);
                    if ($other instanceof PurchaseOrder && $other->id() !== $order->id()) {
                        throw new DomainException('La clave de recepción ya se usó en otra orden.');
                    }
                    if ($order->lines() === []) {
                        throw new DomainException('No se puede recibir una orden sin materiales.');
                    }
                    foreach ($order->lines() as $line) {
                        $this->materialInventory->adjust(
                            $tenant,
                            $source,
                            $line->material(),
                            $line->quantity(),
                            $actorUserId,
                            'purchase:'.hash('sha256', $tenant->id().':'.$key.':'.$order->id().':'.$line->id()),
                            ['purchase_order_id' => $order->id(), 'purchase_order_line_id' => $line->id()],
                        );
                    }
                    $order->markReceived($key, $source->id());
                    return $order;
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('La recepción de compra entró en conflicto con otra solicitud.', 0, $exception);
        }
    }

    private static function assertScope(Tenant $tenant, InventorySource $source, PurchaseOrder $order): void
    {
        if ($order->tenant()->id() !== $tenant->id()
            || $order->supplier()->tenant()->id() !== $tenant->id()
            || $source->tenant()->id() !== $tenant->id()
            || !$source->isActive()
        ) {
            throw new DomainException('La orden, proveedor y fuente deben pertenecer al tenant activo.');
        }
        foreach ($order->lines() as $line) {
            if ($line->material()->tenant()->id() !== $tenant->id() || !$line->material()->isActive()) {
                throw new DomainException('La recepción contiene material ajeno o inactivo.');
            }
        }
    }
}

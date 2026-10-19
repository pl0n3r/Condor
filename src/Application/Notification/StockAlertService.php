<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Notification\Entity\NotificationDelivery;
use App\Domain\Notification\NotificationCatalog;
use DomainException;

/**
 * Builds an in-app alert for an actual downward stock threshold crossing.
 *
 * The inventory caller supplies its configured minimum for this variant/source
 * and persists the returned NotificationDelivery alongside the new movement.
 * Use only with InventoryService's onCreated callback (not on idempotent replay).
 * No external delivery, schema migration or live side effects occur here.
 */
final class StockAlertService
{
    /** @var array<string, true> */
    private array $seenMovements = [];

    public function onMovement(
        Membership $recipient,
        InventoryMovement $movement,
        int $minimum,
    ): ?NotificationDelivery {
        $tenant = $movement->tenant();
        $user = $recipient->user();

        if (
            $recipient->tenant()->id() !== $tenant->id()
            || $movement->source()->tenant()->id() !== $tenant->id()
            || $movement->variant()->tenant()->id() !== $tenant->id()
            || !$recipient->isActive()
            || !$user->isActive()
        ) {
            throw new DomainException(
                'La alerta solo puede dirigirse a un miembro activo del mismo tenant.',
            );
        }

        if ($minimum < 0 || $minimum > 2147483647) {
            throw new DomainException('El mínimo de inventario no es válido.');
        }

        $key = $tenant->id().':'.$movement->id().':'.$recipient->id();
        if (isset($this->seenMovements[$key])) {
            return null;
        }

        $previous = $movement->balanceAfter() - $movement->delta();
        if (
            $movement->delta() >= 0
            || $previous <= $minimum
            || $movement->balanceAfter() > $minimum
        ) {
            return null;
        }

        $alert = new NotificationDelivery(
            $user,
            NotificationCatalog::EVENT_STOCK_LOW,
            NotificationCatalog::CHANNEL_IN_APP,
            [
                'tenant_id' => $tenant->id(),
                'source_id' => $movement->source()->id(),
                'variant_id' => $movement->variant()->id(),
                'movement_id' => $movement->id(),
                'minimum' => $minimum,
                'quantity' => $movement->balanceAfter(),
            ],
        );
        $this->seenMovements[$key] = true;

        return $alert;
    }
}

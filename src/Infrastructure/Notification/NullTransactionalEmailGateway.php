<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;

/**
 * Safe default for flows that can fall back to manual delivery.
 * Machine flows that require real delivery must check isAvailable() first.
 */
final class NullTransactionalEmailGateway implements TransactionalEmailGateway
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function deliver(TransactionalEmailMessage $message): void
    {
        error_log(sprintf(
            'Condor NullTransactionalEmailGateway: correo NO entregado (sin proveedor configurado). template=%s',
            $message->templateKey,
        ));
    }
}

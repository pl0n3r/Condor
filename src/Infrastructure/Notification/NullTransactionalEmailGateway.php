<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;

/**
 * Adaptador seguro por defecto para App\Application\Notification\TransactionalEmailGateway
 * (bloque "Integraciones mediante adaptadores" del roadmap): nunca entrega
 * correo real, solo registra que hubo un intento de entrega. Es el binding
 * que Symfony resuelve automáticamente para la interfaz mientras no exista
 * un proveedor real configurado, así el contrato queda usable/testeable de
 * punta a punta sin depender de credenciales de un proveedor externo.
 *
 * Un proveedor real (p. ej. vía Symfony Mailer/API de un ESP) debe
 * implementar la misma interfaz e reemplazar este binding por config,
 * nunca cambiando el contrato ni el código que lo consume.
 */
final class NullTransactionalEmailGateway implements TransactionalEmailGateway
{
    public function deliver(TransactionalEmailMessage $message): void
    {
        // Nunca registrar el destinatario: coincide con la convención del
        // resto de Observability (sin PII en logs/señales agregadas).
        error_log(sprintf(
            'Condor NullTransactionalEmailGateway: correo NO entregado (sin proveedor configurado). template=%s',
            $message->templateKey,
        ));
    }
}

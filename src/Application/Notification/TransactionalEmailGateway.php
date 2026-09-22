<?php

declare(strict_types=1);

namespace App\Application\Notification;

interface TransactionalEmailGateway
{
    /**
     * Entrega el mensaje mientras los datos sensibles siguen solo en memoria.
     *
     * El adapter concreto nunca debe persistir tokens de activación en texto
     * plano. Si necesita reintentos durables debe diseñar un mecanismo cifrado
     * explícito o regenerable, separado del hash canónico de la invitación.
     */
    public function deliver(TransactionalEmailMessage $message): void;
}

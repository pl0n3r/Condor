<?php

declare(strict_types=1);

namespace App\Application\Notification;

interface TransactionalEmailGateway
{
    public function isAvailable(): bool;

    /** Sensitive template data must remain memory-only. */
    public function deliver(TransactionalEmailMessage $message): void;
}

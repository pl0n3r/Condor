<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailGateway;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class DeferredTransactionalEmailSubscriber
{
    public function __construct(
        private DeferredTransactionalEmailQueue $queue,
        private TransactionalEmailGateway $gateway,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        foreach ($this->queue->drain() as $message) {
            try {
                $this->gateway->deliver($message);
            } catch (Throwable) {
                // No registrar destinatario ni templateData: pueden contener PII o tokens.
                error_log(sprintf(
                    'Condor correo transaccional diferido no entregado. template=%s',
                    $message->templateKey,
                ));
            }
        }
    }
}

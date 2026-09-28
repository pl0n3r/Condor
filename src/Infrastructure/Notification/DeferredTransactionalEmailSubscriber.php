<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        foreach ($this->queue->drain() as $message) {
            try {
                if (!$this->gateway->isAvailable()) {
                    $this->logUndelivered($message, 'gateway_unavailable');
                    continue;
                }

                $this->gateway->deliver($message);
            } catch (Throwable) {
                $this->logUndelivered($message, 'delivery_failed');
            }
        }
    }

    private function logUndelivered(
        TransactionalEmailMessage $message,
        string $reason,
    ): void {
        $template = in_array(
            $message->templateKey,
            ['account_password_reset', 'account_password_changed'],
            true,
        ) ? $message->templateKey : 'unknown';

        try {
            $this->logger->warning(
                'Condor correo transaccional diferido no entregado.',
                [
                    'template' => $template,
                    'reason' => $reason,
                ],
            );
        } catch (Throwable) {
            // El logging no puede convertir una entrega best-effort en fallo de request.
        }
    }
}

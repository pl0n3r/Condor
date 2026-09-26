<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\DeferredTransactionalEmailSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DeferredTransactionalEmailSubscriberTest extends TestCase
{
    public function testDeliveryHappensOnTerminateAndQueueIsDrained(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $message = new TransactionalEmailMessage(
            'admin@example.test',
            'account_password_changed',
            [],
        );
        $queue->enqueue($message);

        $gateway = new class implements TransactionalEmailGateway {
            /** @var list<TransactionalEmailMessage> */
            public array $delivered = [];

            public function deliver(TransactionalEmailMessage $message): void
            {
                $this->delivered[] = $message;
            }
        };

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber = new DeferredTransactionalEmailSubscriber($queue, $gateway);
        $subscriber(new TerminateEvent($kernel, Request::create('/'), new Response()));

        self::assertSame([$message], $gateway->delivered);
        self::assertSame([], $queue->drain());
    }
}

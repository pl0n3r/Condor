<?php

declare(strict_types=1);

namespace App\Tests\Application\Notification;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailMessage;
use PHPUnit\Framework\TestCase;

final class DeferredTransactionalEmailQueueTest extends TestCase
{
    public function testMessagesLiveOnlyInMemoryUntilDrain(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $message = new TransactionalEmailMessage(
            'admin@example.test',
            'account_password_reset',
            ['reset_url' => 'https://example.test/reset#token='.str_repeat('a', 64)],
        );

        $queue->enqueue($message);

        self::assertSame([$message], $queue->drain());
        self::assertSame([], $queue->drain());
    }
}

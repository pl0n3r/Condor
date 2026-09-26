<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\NullTransactionalEmailGateway;
use PHPUnit\Framework\TestCase;

final class NullTransactionalEmailGatewayTest extends TestCase
{
    public function testSafeDefaultIsUnavailableButPreservesManualFallback(): void
    {
        $gateway = new NullTransactionalEmailGateway();
        self::assertInstanceOf(TransactionalEmailGateway::class, $gateway);
        self::assertFalse($gateway->isAvailable());

        $gateway->deliver(new TransactionalEmailMessage(
            'user@example.test',
            'invitation',
            [],
        ));
        $this->addToAssertionCount(1);
    }
}

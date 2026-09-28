<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\DeferredTransactionalEmailSubscriber;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DeferredTransactionalEmailSubscriberTest extends TestCase
{
    public function testAvailableGatewayDeliversOnTerminateAndDrainsQueue(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $message = new TransactionalEmailMessage(
            'admin@example.test',
            'account_password_changed',
            ['source' => 'recovery'],
        );
        $queue->enqueue($message);
        $gateway = new RecordingTransactionalEmailGateway(true);
        [$logger, $handler] = $this->logger();

        $this->subscriber($queue, $gateway, $logger)($this->event());

        self::assertSame([$message], $gateway->delivered);
        self::assertSame([], $queue->drain());
        self::assertSame([], $handler->getRecords());
    }

    public function testUnavailableGatewayIsBestEffortAndLogsOnlyClosedTemplateMetadata(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $queue->enqueue(new TransactionalEmailMessage(
            'sensitive-recipient@example.test',
            'account_password_reset',
            [
                'reset_url' => 'https://example.test/reset#token=super-secret-token',
                'payload_secret' => 'never-log-this',
            ],
        ));
        $gateway = new RecordingTransactionalEmailGateway(false);
        [$logger, $handler] = $this->logger();

        $this->subscriber($queue, $gateway, $logger)($this->event());

        self::assertSame([], $gateway->delivered);
        self::assertSame([], $queue->drain());
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(
            ['template' => 'account_password_reset', 'reason' => 'gateway_unavailable'],
            $records[0]->context,
        );
        $serialized = json_encode($records[0]->context, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sensitive-recipient', $serialized);
        self::assertStringNotContainsString('super-secret-token', $serialized);
        self::assertStringNotContainsString('never-log-this', $serialized);
    }

    public function testDeliveryFailureDropsSensitiveExceptionAndUnknownTemplateKey(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $queue->enqueue(new TransactionalEmailMessage(
            'recipient@example.test',
            "secret-template\nforged-log-line",
            ['token' => 'very-secret-token'],
        ));
        $gateway = new RecordingTransactionalEmailGateway(true, true);
        [$logger, $handler] = $this->logger();

        $this->subscriber($queue, $gateway, $logger)($this->event());

        self::assertSame([], $queue->drain());
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(
            ['template' => 'unknown', 'reason' => 'delivery_failed'],
            $records[0]->context,
        );
        $serialized = json_encode($records[0]->context, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('recipient@example.test', $serialized);
        self::assertStringNotContainsString('very-secret-token', $serialized);
        self::assertStringNotContainsString('secret-template', $serialized);
        self::assertStringNotContainsString('forged-log-line', $serialized);
    }

    /** @return array{Logger, TestHandler} */
    private function logger(): array
    {
        $handler = new TestHandler();

        return [new Logger('condor-test', [$handler]), $handler];
    }

    private function subscriber(
        DeferredTransactionalEmailQueue $queue,
        TransactionalEmailGateway $gateway,
        Logger $logger,
    ): DeferredTransactionalEmailSubscriber {
        return new DeferredTransactionalEmailSubscriber($queue, $gateway, $logger);
    }

    private function event(): TerminateEvent
    {
        return new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/'),
            new Response(),
        );
    }
}

final class RecordingTransactionalEmailGateway implements TransactionalEmailGateway
{
    /** @var list<TransactionalEmailMessage> */
    public array $delivered = [];

    public function __construct(
        private readonly bool $available,
        private readonly bool $throwOnDelivery = false,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function deliver(TransactionalEmailMessage $message): void
    {
        if ($this->throwOnDelivery) {
            throw new RuntimeException(
                'transport failure recipient@example.test very-secret-token',
            );
        }

        $this->delivered[] = $message;
    }
}

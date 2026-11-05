<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Async;

use App\Application\Async\OutboxEnqueuer;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Domain\Async\Entity\OutboxMessage;
use App\Infrastructure\Async\OutboxDispatcher;
use App\Infrastructure\Notification\TransactionalEmailOutboxHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboxDispatcherTest extends KernelTestCase
{
    private const RECIPIENT = 'sintetico@example.test';
    private const TOKEN = 'token-sintetico-1484';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->executeStatement('DELETE FROM condor_outbox_message');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM condor_outbox_message');
        parent::tearDown();
    }

    public function testEnvelopeSharesTheCallersTransaction(): void
    {
        $enqueuer = new OutboxEnqueuer($this->em);
        try {
            $this->em->wrapInTransaction(function () use ($enqueuer): void {
                $enqueuer->enqueue('k-rollback', 'transactional_email', $this->payload());
                $this->em->flush();
                throw new \RuntimeException('fallo posterior de negocio');
            });
        } catch (\RuntimeException) {
        }
        // Doctrine cierra el EntityManager tras un rollback: se reabre uno limpio.
        $registry = static::getContainer()->get('doctrine');
        $registry->resetManager();
        $this->em = $registry->getManager();
        $enqueuer = new OutboxEnqueuer($this->em);

        self::assertSame(0, $this->rows('k-rollback'), 'El rollback debe llevarse el envelope.');

        $this->em->wrapInTransaction(function () use ($enqueuer): void {
            $enqueuer->enqueue('k-commit', 'transactional_email', $this->payload());
        });
        self::assertSame(1, $this->rows('k-commit'));
    }

    public function testEnqueueIsIdempotentByKey(): void
    {
        $enqueuer = new OutboxEnqueuer($this->em);
        $first = $enqueuer->enqueue('k-dup', 'transactional_email', $this->payload());
        $second = $enqueuer->enqueue('k-dup', 'transactional_email', $this->payload());
        $this->em->flush();

        self::assertSame($first, $second);
        self::assertSame(1, $this->rows('k-dup'));
    }

    public function testDeliversOnceWithIdempotencyKeyAndScrubsPayload(): void
    {
        $sent = [];
        $gateway = $this->gateway(true, static function (TransactionalEmailMessage $m) use (&$sent): void {
            $sent[] = $m;
        });
        $this->seed('k-send');

        $dispatcher = $this->dispatcher($gateway, new CollectingLogger());
        self::assertSame(['sent' => 1, 'deferred' => 0, 'failed' => 0], $dispatcher->dispatch());
        self::assertSame(['sent' => 0, 'deferred' => 0, 'failed' => 0], $dispatcher->dispatch());

        self::assertCount(1, $sent);
        self::assertSame('k-send', $sent[0]->templateData['idempotency_key']);
        $message = $this->fetch('k-send');
        self::assertSame(OutboxMessage::STATUS_SENT, $message->status());
        self::assertSame([], $message->payload(), 'El token no debe sobrevivir al envío.');
    }

    public function testWithoutProviderFailsClosedAndIsObservableWithoutLeakingData(): void
    {
        $logger = new CollectingLogger();
        $this->seed('k-noprovider');

        $result = $this->dispatcher($this->gateway(false, null), $logger)->dispatch();

        self::assertSame(['sent' => 0, 'deferred' => 1, 'failed' => 0], $result);
        $message = $this->fetch('k-noprovider');
        self::assertSame(OutboxMessage::STATUS_PENDING, $message->status());
        self::assertSame(0, $message->attempts());
        self::assertSame('provider_unavailable', $message->lastError());
        self::assertCount(1, $logger->records);
        $this->assertNoSensitiveData($logger);
        self::assertSame('provider_unavailable', $logger->records[0]['reason']);
    }

    public function testRepeatedFailuresEndDeadWithoutLeakingData(): void
    {
        $logger = new CollectingLogger();
        $gateway = $this->gateway(true, static function (): void {
            throw new \RuntimeException(self::RECIPIENT.' '.self::TOKEN);
        });
        $this->seed('k-fail');
        $dispatcher = $this->dispatcher($gateway, $logger);

        for ($i = 0; $i < OutboxMessage::MAX_ATTEMPTS; ++$i) {
            $this->em->getConnection()->executeStatement("UPDATE condor_outbox_message SET available_at = '2000-01-01 00:00:00'");
            $this->em->clear();
            $dispatcher->dispatch();
        }

        $message = $this->fetch('k-fail');
        self::assertSame(OutboxMessage::STATUS_DEAD, $message->status());
        self::assertSame(OutboxMessage::MAX_ATTEMPTS, $message->attempts());
        self::assertSame([], $message->payload());
        $this->assertNoSensitiveData($logger);
    }

    private function assertNoSensitiveData(CollectingLogger $logger): void
    {
        $dump = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::RECIPIENT, $dump);
        self::assertStringNotContainsString(self::TOKEN, $dump);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'recipient' => self::RECIPIENT,
            'template' => 'platform_staff_invitation',
            'data' => ['activation_token' => self::TOKEN],
        ];
    }

    private function seed(string $key): void
    {
        (new OutboxEnqueuer($this->em))->enqueue($key, 'transactional_email', $this->payload());
        $this->em->flush();
        $this->em->clear();
    }

    private function fetch(string $key): OutboxMessage
    {
        $this->em->clear();
        $message = $this->em->getRepository(OutboxMessage::class)->findOneBy(['idempotencyKey' => $key]);
        self::assertInstanceOf(OutboxMessage::class, $message);

        return $message;
    }

    private function rows(string $key): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM condor_outbox_message WHERE idempotency_key = ?',
            [$key],
        );
    }

    private function gateway(bool $available, ?callable $deliver): TransactionalEmailGateway
    {
        $gateway = $this->createMock(TransactionalEmailGateway::class);
        $gateway->method('isAvailable')->willReturn($available);
        if ($deliver === null) {
            $gateway->expects(self::never())->method('deliver');
        } else {
            $gateway->method('deliver')->willReturnCallback($deliver);
        }

        return $gateway;
    }

    private function dispatcher(TransactionalEmailGateway $gateway, CollectingLogger $logger): OutboxDispatcher
    {
        return new OutboxDispatcher($this->em, $logger, [new TransactionalEmailOutboxHandler($gateway)]);
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = $context + ['message' => (string) $message];
    }
}

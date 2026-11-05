<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use App\Application\Async\OutboxDelivery;
use App\Application\Async\OutboxHandler;
use App\Application\Async\OutboxUnavailable;
use App\Domain\Async\Entity\OutboxMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Delivers due outbox messages. Each message is processed under a row lock, so
 * two workers never deliver the same envelope. Logs carry only id/topic/reason.
 */
final readonly class OutboxDispatcher implements OutboxDelivery
{
    private const UNAVAILABLE_RETRY = '+5 minutes';

    /** @param iterable<OutboxHandler> $handlers */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[AutowireIterator('app.outbox_handler')]
        private iterable $handlers,
    ) {
    }

    /** @return array{sent: int, deferred: int, failed: int} */
    public function dispatch(int $limit = 50): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM condor_outbox_message WHERE status = ? AND available_at <= ? ORDER BY available_at, id LIMIT '.max(1, $limit),
            [OutboxMessage::STATUS_PENDING, $now->format('Y-m-d H:i:s')],
        );

        $result = ['sent' => 0, 'deferred' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $result[$this->process($id)]++;
        }

        return $result;
    }

    public function deliverNow(string $idempotencyKey): void
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM condor_outbox_message WHERE idempotency_key = ? AND status = ?',
            [$idempotencyKey, OutboxMessage::STATUS_PENDING],
        );
        $outcome = is_string($id) ? $this->process($id) : 'failed';
        if ($outcome === 'sent') {
            return;
        }

        if (is_string($id)) {
            $this->entityManager->wrapInTransaction(function () use ($id): void {
                $message = $this->entityManager->find(OutboxMessage::class, $id, LockMode::PESSIMISTIC_WRITE);
                if ($message instanceof OutboxMessage && $message->status() === OutboxMessage::STATUS_PENDING) {
                    $message->cancel('not_delivered', new DateTimeImmutable('now', new DateTimeZone('UTC')));
                }
            });
        }

        throw $outcome === 'deferred'
            ? new OutboxUnavailable('La entrega de correo no está disponible.')
            : new \RuntimeException('El mensaje no pudo entregarse.');
    }

    /** @return 'sent'|'deferred'|'failed' */
    private function process(string $id): string
    {
        return $this->entityManager->wrapInTransaction(function () use ($id): string {
            $message = $this->entityManager->find(OutboxMessage::class, $id, LockMode::PESSIMISTIC_WRITE);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (!$message instanceof OutboxMessage
                || $message->status() !== OutboxMessage::STATUS_PENDING
                || $message->availableAt() > $now) {
                return 'deferred';
            }

            try {
                $this->handlerFor($message->topic())->handle($message->idempotencyKey(), $message->payload());
                $message->markSent($now);

                return 'sent';
            } catch (OutboxUnavailable) {
                $message->defer('provider_unavailable', $now->modify(self::UNAVAILABLE_RETRY));
                $this->log($message, 'provider_unavailable');

                return 'deferred';
            } catch (Throwable) {
                $message->markFailed('delivery_failed', $now);
                $this->log($message, 'delivery_failed');

                return 'failed';
            }
        });
    }

    private function handlerFor(string $topic): OutboxHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->topic() === $topic) {
                return $handler;
            }
        }

        throw new \LogicException('Outbox topic sin handler.');
    }

    private function log(OutboxMessage $message, string $reason): void
    {
        try {
            $this->logger->warning('Condor outbox no entregado.', [
                'outbox_id' => $message->id(),
                'topic' => $message->topic(),
                'reason' => $reason,
                'status' => $message->status(),
                'attempts' => $message->attempts(),
            ]);
        } catch (Throwable) {
            // El logging no puede romper el despachador.
        }
    }
}

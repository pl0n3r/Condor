<?php

declare(strict_types=1);

namespace App\Application\Async;

use App\Domain\Async\Entity\OutboxMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registers an external effect. It never flushes: the caller's transaction
 * decides atomically whether the business write, audit and envelope exist.
 */
final readonly class OutboxEnqueuer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $idempotencyKey, string $topic, array $payload): OutboxMessage
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof OutboxMessage && $scheduled->idempotencyKey() === $idempotencyKey) {
                return $scheduled;
            }
        }
        $existing = $this->entityManager
            ->getRepository(OutboxMessage::class)
            ->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if ($existing instanceof OutboxMessage) {
            return $existing;
        }

        $message = new OutboxMessage($idempotencyKey, $topic, $payload);
        $this->entityManager->persist($message);

        return $message;
    }
}

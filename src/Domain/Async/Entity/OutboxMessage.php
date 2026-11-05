<?php

declare(strict_types=1);

namespace App\Domain\Async\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable envelope for an external effect. It is persisted in the same
 * transaction as the business write and its audit event. The payload may carry
 * a single-use secret, so it is erased when the message reaches a final state.
 */
#[ORM\Entity]
#[ORM\Table(name: 'condor_outbox_message')]
#[ORM\UniqueConstraint(name: 'uniq_outbox_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_outbox_due', columns: ['status', 'available_at'])]
class OutboxMessage
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DEAD = 'dead';
    public const STATUS_CANCELLED = 'cancelled';
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 190)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'string', length: 80)]
    private string $topic;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'smallint')]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: 'string', length: 60, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    private DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    /** @param array<string, mixed> $payload */
    public function __construct(string $idempotencyKey, string $topic, array $payload)
    {
        $this->id = UlidFactory::new();
        $this->idempotencyKey = $idempotencyKey;
        $this->topic = $topic;
        $this->payload = $payload;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->availableAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function topic(): string
    {
        return $this->topic;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function availableAt(): DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function markSent(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SENT;
        $this->payload = [];
        $this->lastError = null;
        $this->processedAt = $now;
    }

    public function cancel(string $reason, DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->payload = [];
        $this->lastError = $reason;
        $this->processedAt = $now;
    }

    /** Fail-closed hold: the message stays pending and consumes no attempt. */
    public function defer(string $reason, DateTimeImmutable $retryAt): void
    {
        $this->lastError = $reason;
        $this->availableAt = $retryAt;
    }

    public function markFailed(string $reason, DateTimeImmutable $now): void
    {
        ++$this->attempts;
        $this->lastError = $reason;
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->status = self::STATUS_DEAD;
            $this->payload = [];
            $this->processedAt = $now;

            return;
        }
        $this->availableAt = $now->modify(sprintf('+%d seconds', 30 * (2 ** $this->attempts)));
    }
}

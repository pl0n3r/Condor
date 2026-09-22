<?php

declare(strict_types=1);

namespace App\Domain\Notification\Entity;

use App\Domain\Identity\Entity\User;
use App\Domain\Notification\NotificationCatalog;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_notification_delivery')]
#[ORM\Index(
    name: 'idx_notification_delivery_pending',
    columns: ['status', 'available_at'],
)]
class NotificationDelivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private User $user;

    #[ORM\Column(name: 'event_key', type: 'string', length: 120)]
    private string $eventKey;

    #[ORM\Column(name: 'channel_key', type: 'string', length: 32)]
    private string $channelKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'string', length: 24)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'attempt_count', type: 'integer')]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'last_error', type: 'string', length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    private DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'delivered_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $payload */
    public function __construct(
        User $user,
        string $eventKey,
        string $channelKey,
        array $payload,
    ) {
        $now = self::now();
        $this->id = UlidFactory::new();
        $this->user = $user;
        $this->eventKey = NotificationCatalog::normalizeEvent($eventKey);
        $this->channelKey = NotificationCatalog::normalizeChannel($channelKey);
        $this->payload = $payload;
        $this->availableAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function eventKey(): string
    {
        return $this->eventKey;
    }

    public function channelKey(): string
    {
        return $this->channelKey;
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

    public function markDelivered(): void
    {
        $now = self::now();
        $this->status = self::STATUS_DELIVERED;
        $this->deliveredAt = $now;
        $this->lastError = null;
        ++$this->attemptCount;
        $this->updatedAt = $now;
    }

    public function markFailed(
        string $sanitizedError,
        ?DateTimeImmutable $retryAt = null,
    ): void {
        $this->status = self::STATUS_FAILED;
        ++$this->attemptCount;
        $this->lastError = mb_substr(trim($sanitizedError), 0, 255, 'UTF-8');
        $this->availableAt = $retryAt ?? self::now();
        $this->updatedAt = self::now();
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

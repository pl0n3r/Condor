<?php

declare(strict_types=1);

namespace App\Domain\Notification\Entity;

use App\Domain\Identity\Entity\User;
use App\Domain\Notification\NotificationCatalog;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_notification_preference')]
#[ORM\UniqueConstraint(
    name: 'uniq_notification_preference',
    columns: ['user_id', 'event_key', 'channel_key'],
)]
class NotificationPreference
{
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

    #[ORM\Column(type: 'boolean')]
    private bool $enabled;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        string $eventKey,
        string $channelKey,
        bool $enabled,
    ) {
        $this->id = UlidFactory::new();
        $this->user = $user;
        $this->eventKey = NotificationCatalog::normalizeEvent($eventKey);
        $this->channelKey = NotificationCatalog::normalizeChannel($channelKey);
        $this->assertCanDisable($enabled);
        $this->enabled = $enabled;
        $this->createdAt = self::now();
        $this->updatedAt = $this->createdAt;
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->assertCanDisable($enabled);
        $this->enabled = $enabled;
        $this->updatedAt = self::now();
    }

    private function assertCanDisable(bool $enabled): void
    {
        if (!$enabled && NotificationCatalog::isMandatory($this->eventKey)) {
            throw new DomainException(
                'Las notificaciones obligatorias de seguridad no se pueden desactivar.',
            );
        }
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use DomainException;

final class NotificationCatalog
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_IN_APP = 'in_app';

    public const EVENT_ACCOUNT_INVITATION = 'account.invitation';
    public const EVENT_PASSWORD_RESET = 'account.password_reset';
    public const EVENT_INVITATION_ACCEPTED = 'account.invitation_accepted';
    public const EVENT_ORDER_CREATED = 'order.created';
    public const EVENT_DOMAIN_FAILED = 'domain.connection_failed';
    public const EVENT_STOCK_LOW = 'stock.low';

    /** @var list<string> */
    private const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_IN_APP,
    ];

    /** @var list<string> */
    private const EVENTS = [
        self::EVENT_ACCOUNT_INVITATION,
        self::EVENT_PASSWORD_RESET,
        self::EVENT_INVITATION_ACCEPTED,
        self::EVENT_ORDER_CREATED,
        self::EVENT_DOMAIN_FAILED,
        self::EVENT_STOCK_LOW,
    ];

    /** @var array<string, true> */
    private const MANDATORY_EVENTS = [
        self::EVENT_ACCOUNT_INVITATION => true,
        self::EVENT_PASSWORD_RESET => true,
    ];

    public static function normalizeEvent(string $event): string
    {
        $event = trim($event);
        if (!in_array($event, self::EVENTS, true)) {
            throw new DomainException(
                'El tipo de notificación no está registrado.',
            );
        }

        return $event;
    }

    public static function normalizeChannel(string $channel): string
    {
        $channel = trim($channel);
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new DomainException(
                'El canal de notificación no está registrado.',
            );
        }

        return $channel;
    }

    public static function isMandatory(string $event): bool
    {
        $event = self::normalizeEvent($event);

        return isset(self::MANDATORY_EVENTS[$event]);
    }

    /** @return list<string> */
    public static function events(): array
    {
        return self::EVENTS;
    }

    /** @return list<string> */
    public static function channels(): array
    {
        return self::CHANNELS;
    }
}

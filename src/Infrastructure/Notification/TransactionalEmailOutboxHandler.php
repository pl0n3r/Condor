<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Async\OutboxHandler;
use App\Application\Async\OutboxUnavailable;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.outbox_handler')]
final readonly class TransactionalEmailOutboxHandler implements OutboxHandler
{
    public const TOPIC = 'transactional_email';

    public function __construct(private TransactionalEmailGateway $gateway)
    {
    }

    public function topic(): string
    {
        return self::TOPIC;
    }

    public function handle(string $idempotencyKey, array $payload): void
    {
        if (!$this->gateway->isAvailable()) {
            throw new OutboxUnavailable('Sin proveedor de correo configurado.');
        }

        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $this->gateway->deliver(new TransactionalEmailMessage(
            (string) ($payload['recipient'] ?? ''),
            (string) ($payload['template'] ?? ''),
            $data + ['idempotency_key' => $idempotencyKey],
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Async;

interface OutboxHandler
{
    public function topic(): string;

    /**
     * @param array<string, mixed> $payload
     * @throws OutboxUnavailable when the external channel is not configured
     */
    public function handle(string $idempotencyKey, array $payload): void;
}

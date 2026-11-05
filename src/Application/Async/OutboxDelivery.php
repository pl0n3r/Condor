<?php

declare(strict_types=1);

namespace App\Application\Async;

interface OutboxDelivery
{
    /**
     * Attempts one pending envelope right away. If it cannot be delivered the
     * envelope is cancelled (its payload erased) and an exception is thrown, so
     * a request that promised synchronous delivery can undo its business write.
     *
     * @throws OutboxUnavailable|\RuntimeException
     */
    public function deliverNow(string $idempotencyKey): void;
}

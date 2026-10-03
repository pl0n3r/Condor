<?php

declare(strict_types=1);

namespace App\Application\Commercial;

interface BillingIdempotencyStore
{
    public function find(string $idempotencyKey): ?BillingIdempotencyRecord;

    public function save(BillingIdempotencyRecord $record): void;
}

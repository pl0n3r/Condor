<?php

declare(strict_types=1);

namespace App\Application\Commercial\Billing;

use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;

/**
 * Synthetic billing boundary: commercial catalog owns prices and lifecycle
 * owns subscription state. Never sends actual charges or external webhooks.
 */
interface BillingAdapter extends \App\Application\Commercial\BillingAdapter
{
    public function charge(BillingRequest $request, string $actorRef, bool $authorized): BillingResult;

    public function refund(
        string $tenantRef,
        string $chargeRef,
        int $amountMinor,
        string $idempotencyKey,
        string $actorRef,
        bool $authorized,
    ): BillingResult;

    /** @param array<string,mixed> $event */
    public function receiveWebhook(array $event, string $actorRef, bool $authorized): BillingResult;

    public function reconcile(string $tenantRef, string $chargeRef, string $actorRef, bool $authorized): BillingResult;

    /** @return list<array<string,string|int>> */
    public function auditForTenant(string $tenantRef, string $actorRef, bool $authorized): array;
}

<?php

declare(strict_types=1);

namespace App\Application\Commercial\Billing;

use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use DomainException;
use JsonException;

/**
 * In-memory fake. No DI registration, remote provider, credentials, PII,
 * payment settlement, subscriptions write or real-world billing side effects.
 * The existing quote/catalog controls the amount; this fake does NOT price.
 */
final class FakeBillingProvider implements BillingAdapter
{
    /** @var array<string,array<string,array{fingerprint:string,result:BillingResult}>> */
    private array $chargeKeys = [];

    /** @var array<string,array<string,array{amount:int,status:string,remaining:int,result:BillingResult}>> */
    private array $charges = [];

    /** @var array<string,array<string,array{fingerprint:string,result:BillingResult}>> */
    private array $refundKeys = [];

    /** @var array<string,array<string,array{fingerprint:string,result:BillingResult}>> */
    private array $webhookKeys = [];

    /** @var list<array<string,string|int>> */
    private array $audit = [];

    public function submit(BillingRequest $request): BillingResult
    {
        // Legacy interface cannot provide authenticated actor/authorization.
        throw new DomainException('billing_explicit_authorization_required');
    }

    /** @throws JsonException */
    public function charge(BillingRequest $request, string $actorRef, bool $authorized): BillingResult
    {
        $tenant = $request->tenantRef();
        self::scope($tenant, $actorRef, $authorized);
        self::fixture($request->subscriptionRef(), 'subscription');
        self::fixture($request->quoteRef(), 'quote');
        self::fixture($request->idempotencyKey(), 'key');

        $fingerprint = hash('sha256', json_encode($request->toArray(), JSON_THROW_ON_ERROR));
        $key = $request->idempotencyKey();
        $existing = $this->chargeKeys[$tenant][$key] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                throw new DomainException('billing_idempotency_conflict');
            }
            return $existing['result'];
        }

        $reference = self::syntheticRef('charge', $tenant.'|'.$key);
        $result = new BillingResult(
            'prepared', $reference, self::syntheticRef('evidence', $reference),
        );
        $this->charges[$tenant][$reference] = [
            'amount' => $request->amountMinor(),
            'remaining' => $request->amountMinor(),
            'status' => 'prepared',
            'result' => $result,
        ];
        $this->chargeKeys[$tenant][$key] = ['fingerprint' => $fingerprint, 'result' => $result];
        $this->record($tenant, $actorRef, 'charge_prepared', $reference, $key);
        return $result;
    }

    public function refund(
        string $tenantRef,
        string $chargeRef,
        int $amountMinor,
        string $idempotencyKey,
        string $actorRef,
        bool $authorized,
    ): BillingResult {
        self::scope($tenantRef, $actorRef, $authorized);
        self::fixture($chargeRef, 'charge');
        self::fixture($idempotencyKey, 'key');
        if ($amountMinor <= 0) {
            throw new DomainException('invalid_refund_amount');
        }
        $charge = $this->charges[$tenantRef][$chargeRef] ?? null;
        if ($charge === null) {
            throw new DomainException('billing_charge_not_found');
        }

        $fingerprint = hash('sha256', $chargeRef.'|'.$amountMinor);
        $prior = $this->refundKeys[$tenantRef][$idempotencyKey] ?? null;
        if ($prior !== null) {
            if (!hash_equals($prior['fingerprint'], $fingerprint)) {
                throw new DomainException('billing_refund_idempotency_conflict');
            }
            return $prior['result'];
        }
        if ($charge['status'] !== 'accepted' || $amountMinor > $charge['remaining']) {
            throw new DomainException('billing_refund_requires_accepted_charge_and_balance');
        }

        $reference = self::syntheticRef('refund', $tenantRef.'|'.$idempotencyKey);
        $result = new BillingResult('accepted', $reference, self::syntheticRef('evidence', $reference));
        $this->charges[$tenantRef][$chargeRef]['remaining'] -= $amountMinor;
        $this->refundKeys[$tenantRef][$idempotencyKey] = [
            'fingerprint' => $fingerprint, 'result' => $result,
        ];
        $this->record($tenantRef, $actorRef, 'refund_accepted', $chargeRef, $idempotencyKey);
        return $result;
    }

    /** @param array<string,mixed> $event */
    public function receiveWebhook(array $event, string $actorRef, bool $authorized): BillingResult
    {
        $expected = ['charge_ref', 'event_ref', 'outcome', 'tenant_ref'];
        $keys = array_keys($event);
        sort($keys);
        if ($keys !== $expected
            || !is_string($event['charge_ref']) || !is_string($event['event_ref'])
            || !is_string($event['outcome']) || !is_string($event['tenant_ref'])
        ) {
            throw new DomainException('invalid_fake_webhook_shape');
        }

        $tenant = $event['tenant_ref'];
        self::scope($tenant, $actorRef, $authorized);
        self::fixture($event['charge_ref'], 'charge');
        self::fixture($event['event_ref'], 'event');
        if (!in_array($event['outcome'], ['accepted', 'rejected'], true)) {
            throw new DomainException('invalid_fake_webhook_outcome');
        }
        $chargeRef = $event['charge_ref'];
        $charge = $this->charges[$tenant][$chargeRef] ?? null;
        if ($charge === null) {
            throw new DomainException('fake_webhook_charge_not_found');
        }
        $eventRef = $event['event_ref'];
        $fingerprint = hash('sha256', $tenant.'|'.$chargeRef.'|'.$event['outcome']);
        $prior = $this->webhookKeys[$tenant][$eventRef] ?? null;
        if ($prior !== null) {
            if (!hash_equals($prior['fingerprint'], $fingerprint)) {
                throw new DomainException('fake_webhook_replay_conflict');
            }
            return $prior['result'];
        }
        if ($charge['status'] !== 'prepared') {
            throw new DomainException('fake_webhook_conflicting_terminal_state');
        }

        $result = new BillingResult(
            $event['outcome'],
            $charge['result']->providerRef(),
            self::syntheticRef('evidence', $eventRef),
        );
        $this->charges[$tenant][$chargeRef]['status'] = $event['outcome'];
        $this->charges[$tenant][$chargeRef]['result'] = $result;
        $this->webhookKeys[$tenant][$eventRef] = [
            'fingerprint' => $fingerprint, 'result' => $result,
        ];
        $this->record($tenant, $actorRef, 'webhook_'.$event['outcome'], $chargeRef, $eventRef);
        return $result;
    }

    public function reconcile(
        string $tenantRef,
        string $chargeRef,
        string $actorRef,
        bool $authorized,
    ): BillingResult {
        self::scope($tenantRef, $actorRef, $authorized);
        self::fixture($chargeRef, 'charge');
        $charge = $this->charges[$tenantRef][$chargeRef] ?? null;
        if ($charge === null || $charge['status'] === 'prepared') {
            throw new DomainException('billing_reconciliation_required_or_missing');
        }
        return $charge['result'];
    }

    /** @return list<array<string,string|int>> */
    public function auditForTenant(string $tenantRef, string $actorRef, bool $authorized): array
    {
        self::scope($tenantRef, $actorRef, $authorized);
        return array_values(array_filter(
            $this->audit,
            static fn (array $row): bool => $row['tenant_ref'] === $tenantRef,
        ));
    }

    private static function scope(string $tenantRef, string $actorRef, bool $authorized): void
    {
        self::fixture($tenantRef, 'tenant');
        self::fixture($actorRef, 'actor');
        if (!$authorized) {
            throw new DomainException('billing_permission_denied');
        }
    }

    private static function fixture(string $value, string $type): void
    {
        if (preg_match('/^fixture-'.preg_quote($type, '/').'-[a-z0-9][a-z0-9-]{0,47}$/D', $value) !== 1) {
            throw new DomainException('billing_synthetic_only_'.$type);
        }
    }

    private static function syntheticRef(string $type, string $value): string
    {
        return 'fixture-'.$type.'-'.substr(hash('sha256', $value), 0, 24);
    }

    private function record(
        string $tenant,
        string $actor,
        string $action,
        string $objectRef,
        string $idempotencyKey,
    ): void {
        $this->audit[] = [
            'tenant_ref' => $tenant,
            'actor_ref' => $actor,
            'action' => $action,
            'object_ref' => $objectRef,
            'idempotency_key' => $idempotencyKey,
            'sequence' => count($this->audit) + 1,
        ];
    }
}

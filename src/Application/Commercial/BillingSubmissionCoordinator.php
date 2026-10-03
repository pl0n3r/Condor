<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;
use JsonException;

final readonly class BillingSubmissionCoordinator
{
    public function __construct(
        private BillingAdapter $adapter,
        private BillingIdempotencyStore $store,
    ) {
    }

    public function submit(BillingRequest $request): BillingResult
    {
        $fingerprint = self::fingerprint($request);
        $existing = $this->store->find($request->idempotencyKey());

        if ($existing !== null) {
            if (!hash_equals($existing->requestFingerprint(), $fingerprint)) {
                throw new DomainException('billing_idempotency_conflict');
            }

            if ($existing->result()->outcome() === 'unknown') {
                throw new DomainException('billing_reconciliation_required');
            }

            return $existing->result();
        }

        $result = $this->adapter->submit($request);
        $this->store->save(new BillingIdempotencyRecord(
            $request->idempotencyKey(),
            $fingerprint,
            $result,
        ));

        return $result;
    }

    /**
     * @throws JsonException
     */
    private static function fingerprint(BillingRequest $request): string
    {
        $canonicalPayload = json_encode(
            $request->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $canonicalPayload);
    }
}

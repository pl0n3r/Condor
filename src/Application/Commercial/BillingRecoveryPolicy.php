<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use DomainException;

final readonly class BillingRecoveryPolicy
{
    public function actionFor(BillingResult $result): string
    {
        return match ($result->outcome()) {
            'accepted', 'rejected' => 'terminal',
            'prepared' => 'prepared',
            'unknown' => 'reconciliation_required',
            default => throw new DomainException('invalid_billing_recovery_outcome'),
        };
    }

    public function reconcile(
        BillingRequest $request,
        BillingResult $result,
        BillingReconciliationEvidence $evidence,
    ): BillingResult {
        if ($result->outcome() !== 'unknown') {
            throw new DomainException('billing_reconciliation_not_required');
        }

        if (!hash_equals($request->idempotencyKey(), $evidence->idempotencyKey())) {
            throw new DomainException('billing_reconciliation_mismatch');
        }

        return new BillingResult(
            $evidence->outcome(),
            $result->providerRef(),
            $evidence->evidenceRef(),
        );
    }
}

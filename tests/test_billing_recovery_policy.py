#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class BillingRecoveryPolicyTests(unittest.TestCase):
    def test_unknown_requires_explicit_reconciliation_and_never_becomes_blind_retry(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingReconciliationEvidence;
use App\Application\Commercial\BillingRecoveryPolicy;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;

$request = BillingRequest::fromArray([
    'tenant_ref' => 'tenant:synthetic-a',
    'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
    'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
    'amount_minor' => 199900,
    'currency' => 'COP',
    'idempotency_key' => 'billing:tenant-a:2026-10',
]);

$unknown = new BillingResult(
    'unknown',
    'processor:synthetic-001',
    'billing:evidence:unknown-001',
);

$policy = new BillingRecoveryPolicy();
$evidence = BillingReconciliationEvidence::fromArray([
    'idempotency_key' => 'billing:tenant-a:2026-10',
    'outcome' => 'accepted',
    'evidence_ref' => 'billing:reconciliation:accepted-001',
    'observed_at' => '2026-10-03T09:10:00.000000Z',
]);
$resolved = $policy->reconcile($request, $unknown, $evidence);

print json_encode([
    'unknown_action' => $policy->actionFor($unknown),
    'accepted_action' => $policy->actionFor($resolved),
    'prepared_action' => $policy->actionFor(new BillingResult(
        'prepared',
        null,
        'billing:evidence:prepared-001',
    )),
    'resolved' => $resolved->toArray(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("reconciliation_required", observed["unknown_action"])
        self.assertNotEqual("retry", observed["unknown_action"])
        self.assertEqual("terminal", observed["accepted_action"])
        self.assertEqual("prepared", observed["prepared_action"])
        self.assertEqual("accepted", observed["resolved"]["outcome"])
        self.assertEqual("processor:synthetic-001", observed["resolved"]["provider_ref"])
        self.assertEqual(
            "billing:reconciliation:accepted-001",
            observed["resolved"]["evidence_ref"],
        )

    def test_mismatched_sensitive_or_ambiguous_reconciliation_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingReconciliationEvidence;
use App\Application\Commercial\BillingRecoveryPolicy;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;

$request = BillingRequest::fromArray([
    'tenant_ref' => 'tenant:synthetic-a',
    'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
    'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
    'amount_minor' => 199900,
    'currency' => 'COP',
    'idempotency_key' => 'billing:tenant-a:2026-10',
]);

$policy = new BillingRecoveryPolicy();
$unknown = new BillingResult(
    'unknown',
    null,
    'billing:evidence:unknown-001',
);
$out = [];

try {
    $policy->reconcile(
        $request,
        $unknown,
        BillingReconciliationEvidence::fromArray([
            'idempotency_key' => 'billing:other-tenant:2026-10',
            'outcome' => 'rejected',
            'evidence_ref' => 'billing:reconciliation:rejected-001',
            'observed_at' => '2026-10-03T09:10:00.000000Z',
        ]),
    );
    $out['mismatch'] = 'accepted';
} catch (DomainException $exception) {
    $out['mismatch'] = $exception->getMessage();
}

$invalidPayloads = [
    'ambiguous' => [
        'idempotency_key' => 'billing:tenant-a:2026-10',
        'outcome' => 'unknown',
        'evidence_ref' => 'billing:reconciliation:ambiguous-001',
        'observed_at' => '2026-10-03T09:10:00.000000Z',
    ],
    'free_form' => [
        'idempotency_key' => 'billing:tenant-a:2026-10',
        'outcome' => 'accepted',
        'evidence_ref' => 'billing:reconciliation:accepted-001',
        'observed_at' => '2026-10-03T09:10:00.000000Z',
        'note' => 'forbidden',
    ],
    'sensitive' => [
        'idempotency_key' => 'billing:tenant-a:2026-10',
        'outcome' => 'accepted',
        'evidence_ref' => 'email:person@example.com',
        'observed_at' => '2026-10-03T09:10:00.000000Z',
    ],
    'timestamp' => [
        'idempotency_key' => 'billing:tenant-a:2026-10',
        'outcome' => 'accepted',
        'evidence_ref' => 'billing:reconciliation:accepted-001',
        'observed_at' => '2026-10-03 09:10:00',
    ],
];

foreach ($invalidPayloads as $name => $payload) {
    try {
        BillingReconciliationEvidence::fromArray($payload);
        $out[$name] = 'accepted';
    } catch (DomainException $exception) {
        $out[$name] = $exception->getMessage();
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("billing_reconciliation_mismatch", observed["mismatch"])
        self.assertNotEqual("accepted", observed["ambiguous"])
        self.assertNotEqual("accepted", observed["free_form"])
        self.assertNotEqual("accepted", observed["sensitive"])
        self.assertNotEqual("accepted", observed["timestamp"])


if __name__ == "__main__":
    unittest.main()

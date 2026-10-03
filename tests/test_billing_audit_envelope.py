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


class BillingAuditEnvelopeTests(unittest.TestCase):
    def test_envelope_keeps_only_minimized_canonical_billing_evidence(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingAuditEnvelope;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;

$request = BillingRequest::fromArray([
    'tenant_ref' => 'tenant:synthetic-a',
    'subscription_ref' => 'subscription:synthetic-a',
    'quote_ref' => 'quote:synthetic-a',
    'amount_minor' => 199900,
    'currency' => 'COP',
    'idempotency_key' => 'billing:tenant-a:2026-10',
]);

$result = new BillingResult(
    'accepted',
    'processor:synthetic-001',
    'billing:evidence:accepted-001',
);

$first = BillingAuditEnvelope::fromContracts(
    $request,
    $result,
    '2026-10-03T09:20:00.000000Z',
)->toArray();

$second = BillingAuditEnvelope::fromContracts(
    BillingRequest::fromArray($request->toArray()),
    BillingResult::fromArray($result->toArray()),
    '2026-10-03T09:20:00.000000Z',
)->toArray();

print json_encode(['first' => $first, 'second' => $second], JSON_THROW_ON_ERROR);
"""
        )

        expected_keys = [
            "tenant_ref",
            "idempotency_key",
            "request_fingerprint",
            "outcome",
            "provider_ref",
            "evidence_ref",
            "observed_at",
        ]
        self.assertEqual(expected_keys, list(observed["first"].keys()))
        self.assertEqual(observed["first"], observed["second"])
        self.assertEqual("tenant:synthetic-a", observed["first"]["tenant_ref"])
        self.assertEqual("billing:tenant-a:2026-10", observed["first"]["idempotency_key"])
        self.assertRegex(observed["first"]["request_fingerprint"], r"^[a-f0-9]{64}$")
        self.assertEqual("accepted", observed["first"]["outcome"])
        self.assertEqual("processor:synthetic-001", observed["first"]["provider_ref"])
        self.assertEqual("billing:evidence:accepted-001", observed["first"]["evidence_ref"])

    def test_pii_secrets_payment_data_and_free_form_payload_fail_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingAuditEnvelope;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;

$valid = BillingAuditEnvelope::fromContracts(
    BillingRequest::fromArray([
        'tenant_ref' => 'tenant:synthetic-a',
        'subscription_ref' => 'subscription:synthetic-a',
        'quote_ref' => 'quote:synthetic-a',
        'amount_minor' => 199900,
        'currency' => 'COP',
        'idempotency_key' => 'billing:tenant-a:2026-10',
    ]),
    new BillingResult('unknown', null, 'billing:evidence:unknown-001'),
    '2026-10-03T09:20:00.000000Z',
)->toArray();

$cases = [
    'note' => [...$valid, 'note' => 'synthetic'],
    'email' => [...$valid, 'email' => 'synthetic'],
    'pan' => [...$valid, 'pan' => 'synthetic'],
    'cvv' => [...$valid, 'cvv' => 'synthetic'],
    'secret' => [...$valid, 'secret' => 'synthetic'],
    'provider_payload' => [...$valid, 'provider_payload' => ['status' => 'synthetic']],
    'tenant_sensitive' => [...$valid, 'tenant_ref' => 'email:synthetic'],
    'idempotency_provider' => [...$valid, 'idempotency_key' => 'provider:synthetic-key'],
    'provider_secret' => [...$valid, 'provider_ref' => 'token:synthetic'],
    'fingerprint' => [...$valid, 'request_fingerprint' => 'not-a-sha256'],
    'timestamp' => [...$valid, 'observed_at' => '2026-10-03 09:20:00'],
];

$out = [];
foreach ($cases as $name => $payload) {
    try {
        BillingAuditEnvelope::fromArray($payload);
        $out[$name] = 'accepted';
    } catch (DomainException $exception) {
        $out[$name] = $exception->getMessage();
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        for name, outcome in observed.items():
            self.assertNotEqual("accepted", outcome, name)


if __name__ == "__main__":
    unittest.main()

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


class BillingAdapterContractTests(unittest.TestCase):
    def test_request_and_result_are_provider_neutral_minimized_and_idempotent(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingAdapter;
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

$adapter = new class implements BillingAdapter {
    public function submit(BillingRequest $request): BillingResult
    {
        return BillingResult::fromArray([
            'outcome' => 'prepared',
            'provider_ref' => null,
            'evidence_ref' => 'billing:evidence:' . $request->idempotencyKey(),
        ]);
    }
};

$first = $adapter->submit($request);
$second = $adapter->submit(BillingRequest::fromArray($request->toArray()));

print json_encode([
    'request' => $request->toArray(),
    'first' => $first->toArray(),
    'second' => $second->toArray(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "tenant_ref": "tenant:synthetic-a",
                "subscription_ref": "subscription:01hzzzzzzzzzzzzzzzzzzzzzzz",
                "quote_ref": "quote:01hyyyyyyyyyyyyyyyyyyyyyyy",
                "amount_minor": 199900,
                "currency": "COP",
                "idempotency_key": "billing:tenant-a:2026-10",
            },
            observed["request"],
        )
        self.assertEqual(observed["first"], observed["second"])
        self.assertEqual(
            {
                "outcome": "prepared",
                "provider_ref": None,
                "evidence_ref": "billing:evidence:billing:tenant-a:2026-10",
            },
            observed["first"],
        )

    def test_sensitive_ambiguous_or_provider_specific_payload_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;

$valid = [
    'tenant_ref' => 'tenant:synthetic-a',
    'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
    'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
    'amount_minor' => 199900,
    'currency' => 'COP',
    'idempotency_key' => 'billing:tenant-a:2026-10',
];

$cases = [
    'email' => $valid + ['email' => 'person@example.com'],
    'token' => $valid + ['token' => 'secret'],
    'provider' => $valid + ['provider' => 'vendor-a'],
    'card' => $valid + ['card_number' => '4111111111111111'],
    'sensitive_ref' => [...$valid, 'tenant_ref' => 'token:secret'],
    'provider_specific_key' => [...$valid, 'idempotency_key' => 'provider:vendor-a'],
    'currency' => [...$valid, 'currency' => 'cop'],
    'amount' => [...$valid, 'amount_minor' => 0],
];

$out = [];
foreach ($cases as $name => $payload) {
    try {
        BillingRequest::fromArray($payload);
        $out[$name] = 'accepted';
    } catch (DomainException $exception) {
        $out[$name] = $exception->getMessage();
    }
}

foreach ([
    'outcome' => ['outcome' => 'paid', 'provider_ref' => null, 'evidence_ref' => 'billing:evidence:test'],
    'provider_ref' => ['outcome' => 'unknown', 'provider_ref' => 'token:providersecret', 'evidence_ref' => 'billing:evidence:test'],
    'evidence_ref' => ['outcome' => 'accepted', 'provider_ref' => null, 'evidence_ref' => 'token:secret'],
] as $name => $payload) {
    try {
        BillingResult::fromArray($payload);
        $out['result_' . $name] = 'accepted';
    } catch (DomainException $exception) {
        $out['result_' . $name] = $exception->getMessage();
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "email",
                "token",
                "provider",
                "card",
                "sensitive_ref",
                "provider_specific_key",
                "currency",
                "amount",
                "result_outcome",
                "result_provider_ref",
                "result_evidence_ref",
            },
            set(observed),
        )
        self.assertTrue(all(value != "accepted" for value in observed.values()))


if __name__ == "__main__":
    unittest.main()

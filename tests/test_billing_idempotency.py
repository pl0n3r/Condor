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


class BillingIdempotencyTests(unittest.TestCase):
    def test_same_key_and_same_request_replays_recorded_result_without_second_adapter_submit(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingAdapter;
use App\Application\Commercial\BillingIdempotencyRecord;
use App\Application\Commercial\BillingIdempotencyStore;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use App\Application\Commercial\BillingSubmissionCoordinator;

$adapter = new class implements BillingAdapter {
    public int $submits = 0;

    public function submit(BillingRequest $request): BillingResult
    {
        ++$this->submits;

        return new BillingResult(
            'accepted',
            'processor:synthetic-001',
            'billing:evidence:accepted-001',
        );
    }
};

$store = new class implements BillingIdempotencyStore {
    /** @var array<string,BillingIdempotencyRecord> */
    private array $records = [];

    public function find(string $idempotencyKey): ?BillingIdempotencyRecord
    {
        return $this->records[$idempotencyKey] ?? null;
    }

    public function save(BillingIdempotencyRecord $record): void
    {
        $this->records[$record->idempotencyKey()] = $record;
    }
};

$request = BillingRequest::fromArray([
    'tenant_ref' => 'tenant:synthetic-a',
    'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
    'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
    'amount_minor' => 199900,
    'currency' => 'COP',
    'idempotency_key' => 'billing:tenant-a:2026-10',
]);

$coordinator = new BillingSubmissionCoordinator($adapter, $store);
$first = $coordinator->submit($request);
$second = $coordinator->submit(BillingRequest::fromArray($request->toArray()));

print json_encode([
    'submits' => $adapter->submits,
    'first' => $first->toArray(),
    'second' => $second->toArray(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(1, observed["submits"])
        self.assertEqual(observed["first"], observed["second"])
        self.assertEqual("accepted", observed["first"]["outcome"])

    def test_same_key_with_changed_payload_or_unknown_record_fails_closed_without_double_submit(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\BillingAdapter;
use App\Application\Commercial\BillingIdempotencyRecord;
use App\Application\Commercial\BillingIdempotencyStore;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use App\Application\Commercial\BillingSubmissionCoordinator;

$adapter = new class implements BillingAdapter {
    public int $submits = 0;

    public function submit(BillingRequest $request): BillingResult
    {
        ++$this->submits;

        return new BillingResult(
            'unknown',
            null,
            'billing:evidence:unknown-001',
        );
    }
};

$store = new class implements BillingIdempotencyStore {
    /** @var array<string,BillingIdempotencyRecord> */
    private array $records = [];

    public function find(string $idempotencyKey): ?BillingIdempotencyRecord
    {
        return $this->records[$idempotencyKey] ?? null;
    }

    public function save(BillingIdempotencyRecord $record): void
    {
        $this->records[$record->idempotencyKey()] = $record;
    }
};

$request = BillingRequest::fromArray([
    'tenant_ref' => 'tenant:synthetic-a',
    'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
    'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
    'amount_minor' => 199900,
    'currency' => 'COP',
    'idempotency_key' => 'billing:tenant-a:2026-10',
]);

$coordinator = new BillingSubmissionCoordinator($adapter, $store);
$first = $coordinator->submit($request);

$out = [
    'first_outcome' => $first->outcome(),
];

try {
    $coordinator->submit($request);
    $out['unknown_retry'] = 'accepted';
} catch (DomainException $exception) {
    $out['unknown_retry'] = $exception->getMessage();
}

$changed = BillingRequest::fromArray([
    ...$request->toArray(),
    'amount_minor' => 299900,
]);

try {
    $coordinator->submit($changed);
    $out['changed_payload'] = 'accepted';
} catch (DomainException $exception) {
    $out['changed_payload'] = $exception->getMessage();
}

$out['submits'] = $adapter->submits;

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("unknown", observed["first_outcome"])
        self.assertEqual("billing_reconciliation_required", observed["unknown_retry"])
        self.assertEqual("billing_idempotency_conflict", observed["changed_payload"])
        self.assertEqual(1, observed["submits"])


if __name__ == "__main__":
    unittest.main()

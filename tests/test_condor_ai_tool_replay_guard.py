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


class CondorAiToolReplayGuardTests(unittest.TestCase):
    def test_first_claim_succeeds_and_duplicate_claim_is_rejected_locally(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolReplayKey;
use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'content.draft.update',
    'knowledge_refs' => [],
], $policy);

$key = static function (string $ref) use ($context, $policy): AiToolReplayKey {
    $request = AiToolRequest::fromArray($context, $policy, [
        'tenant_id' => 'tenant-a',
        'tool' => 'content.draft.update',
        'request_ref' => $ref,
    ]);

    return AiToolReplayKey::fromRequest($request, $context, $policy);
};

$guard = new AiToolReplayGuard();
$first = $key('request:draft-001');
$second = $key('request:draft-002');

print json_encode([
    'first' => $guard->claim($first),
    'duplicate_first' => $guard->claim($first),
    'second' => $guard->claim($second),
    'duplicate_second' => $guard->claim($second),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "first": True,
                "duplicate_first": False,
                "second": True,
                "duplicate_second": False,
            },
            observed,
        )

    def test_guard_is_bounded_and_fails_closed_without_io(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolReplayKey;
use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'content.draft.update',
    'knowledge_refs' => [],
], $policy);

$key = static function (string $ref) use ($context, $policy): AiToolReplayKey {
    $request = AiToolRequest::fromArray($context, $policy, [
        'tenant_id' => 'tenant-a',
        'tool' => 'content.draft.update',
        'request_ref' => $ref,
    ]);

    return AiToolReplayKey::fromRequest($request, $context, $policy);
};

$guard = new AiToolReplayGuard();
$first = null;
$accepted = 0;

for ($index = 0; $index < 128; ++$index) {
    $candidate = $key(sprintf('request:claim-%03d', $index));
    $first ??= $candidate;
    if ($guard->claim($candidate)) {
        ++$accepted;
    }
}

$overflowRejected = false;
try {
    $guard->claim($key('request:claim-128'));
} catch (DomainException) {
    $overflowRejected = true;
}

print json_encode([
    'accepted' => $accepted,
    'overflow_rejected' => $overflowRejected,
    'first_still_replay' => $first instanceof AiToolReplayKey
        ? !$guard->claim($first)
        : false,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "accepted": 128,
                "overflow_rejected": True,
                "first_still_replay": True,
            },
            observed,
        )

        source = (ROOT / "src/Application/AI/AiToolReplayGuard.php").read_text(
            encoding="utf-8"
        ).lower()
        for forbidden in (
            "time(",
            "microtime",
            "ttl",
            "redis",
            "pdo",
            "doctrine",
            "file_put_contents",
            "fopen(",
            "curl",
            "http://",
            "https://",
            "getenv",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

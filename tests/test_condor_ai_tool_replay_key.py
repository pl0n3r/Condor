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


class CondorAiToolReplayKeyTests(unittest.TestCase):
    def test_same_canonical_request_produces_stable_opaque_replay_key(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolReplayKey;
use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'content.draft.update',
    'knowledge_refs' => ['knowledge:catalog-public'],
], $policy);

$make = static function (string $tool) use ($context, $policy): AiToolRequest {
    return AiToolRequest::fromArray($context, $policy, [
        'tenant_id' => 'tenant-a',
        'tool' => $tool,
        'request_ref' => 'request:draft-001',
    ]);
};

$first = AiToolReplayKey::fromRequest(
    $make('content.draft.update'),
    $context,
    $policy,
);
$second = AiToolReplayKey::fromRequest(
    $make('CONTENT.DRAFT.UPDATE'),
    $context,
    $policy,
);

print json_encode([
    'first' => $first->value(),
    'second' => $second->value(),
    'string' => (string) $first,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(observed["first"], observed["second"])
        self.assertEqual(observed["first"], observed["string"])
        self.assertRegex(str(observed["first"]), r"^replay:[a-f0-9]{64}$")
        lowered = str(observed["first"]).lower()
        for forbidden in (
            "tenant-a",
            "content.draft.update",
            "request:draft-001",
            "knowledge:catalog-public",
        ):
            self.assertNotIn(forbidden, lowered)

    def test_tenant_tool_or_request_change_produces_distinct_key_without_payload_leak(
        self,
    ) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolReplayKey;
use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$cases = [
    ['tenant-a', 'content.draft.update', 'request:draft-001'],
    ['tenant-b', 'content.draft.update', 'request:draft-001'],
    ['tenant-a', 'settings.draft.update', 'request:draft-001'],
    ['tenant-a', 'content.draft.update', 'request:draft-002'],
];

$out = [];
foreach ($cases as [$tenantId, $tool, $requestRef]) {
    $context = AiTenantContext::fromArray([
        'tenant_id' => $tenantId,
        'tool' => $tool,
        'knowledge_refs' => ['knowledge:private-segment'],
    ], $policy);
    $request = AiToolRequest::fromArray($context, $policy, [
        'tenant_id' => $tenantId,
        'tool' => $tool,
        'request_ref' => $requestRef,
    ]);
    $out[] = AiToolReplayKey::fromRequest($request, $context, $policy)->value();
}

print json_encode(['keys' => $out], JSON_THROW_ON_ERROR);
"""
        )

        keys = observed["keys"]
        self.assertIsInstance(keys, list)
        self.assertEqual(4, len(keys))
        self.assertEqual(4, len(set(keys)))

        for key in keys:
            self.assertRegex(str(key), r"^replay:[a-f0-9]{64}$")

        serialized = json.dumps(keys).lower()
        for forbidden in (
            "tenant-a",
            "tenant-b",
            "content.draft.update",
            "settings.draft.update",
            "request:draft-001",
            "request:draft-002",
            "knowledge:private-segment",
            "payload",
            "evidence",
            "prompt",
        ):
            self.assertNotIn(forbidden, serialized)

        source = (ROOT / "src/Application/AI/AiToolReplayKey.php").read_text(
            encoding="utf-8"
        ).lower()
        for forbidden in (
            "curl",
            "http://",
            "https://",
            "pdo",
            "doctrine",
            "redis",
            "file_put_contents",
            "fopen(",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiConversationResultBridgeTests(unittest.TestCase):
    def test_tool_turn_returns_only_descriptor_validated_result(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray(
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'knowledge_refs' => [],
    ],
    $policy,
);
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
        'output_names' => ['label', 'product_ref'],
        'required_outputs' => ['product_ref'],
    ],
);
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static fn (array $inputs): array => [
            'product_ref' => 'product:42',
            'label' => 'Industrial',
        ],
    ]],
);

$result = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'inputs' => [],
        'request_ref' => 'request:bridge-result',
        'evidence_ref' => 'evidence:bridge-result',
        'timestamp' => '2026-10-04T10:00:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T10:00:00Z'),
    $registry,
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("completed", observed["status"])
        self.assertTrue(observed["executed"])
        self.assertEqual(
            {"product_ref": "product:42", "label": "Industrial"},
            observed["tool_result"],
        )
        receipt = observed["receipt"]
        audit = observed["audit"]
        assert isinstance(receipt, dict)
        assert isinstance(audit, dict)
        self.assertNotIn("tool_result", receipt)
        self.assertNotIn("product_ref", receipt)
        self.assertNotIn("label", receipt)
        self.assertNotIn("product_ref", audit)
        self.assertNotIn("label", audit)
        self.assertIn("'version' => '0.1.163'", VERSION.read_text(encoding="utf-8"))

    def test_invalid_handler_output_handoffs_without_raw_leak(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray(
    [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'knowledge_refs' => [],
    ],
    $policy,
);
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
        'output_names' => ['product_ref'],
        'required_outputs' => ['product_ref'],
    ],
);

$cases = [
    'nested' => ['product_ref' => ['raw' => 'opaque']],
    'unknown' => ['product_ref' => 'product:42', 'unknown' => 'secret'],
];

$out = [];
foreach ($cases as $name => $handlerResult) {
    $registry = AiToolRegistry::fromArray(
        $policy,
        [[
            'tool' => 'catalog.read',
            'descriptor' => $descriptor,
            'handler' => static fn (array $inputs): array => $handlerResult,
        ]],
    );

    $result = AiConversationCore::turn(
        $context,
        $policy,
        [
            'intent' => 'tool',
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'inputs' => [],
            'request_ref' => 'request:invalid-bridge',
            'evidence_ref' => 'evidence:invalid-bridge',
            'timestamp' => '2026-10-04T10:00:00+00:00',
        ],
        [],
        new DateTimeImmutable('2026-10-04T10:00:00Z'),
        $registry,
    );

    $out[$name] = [
        'status' => $result['status'],
        'reason' => $result['reason'],
        'executed' => $result['executed'],
        'receipt_outcome' => $result['receipt']['outcome'],
        'has_tool_result' => array_key_exists('tool_result', $result),
        'serialized' => json_encode($result, JSON_THROW_ON_ERROR),
    ];
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        for name, result in observed.items():
            with self.subTest(case=name):
                assert isinstance(result, dict)
                self.assertEqual("handoff", result["status"])
                self.assertEqual("tool_failed", result["reason"])
                self.assertTrue(result["executed"])
                self.assertEqual("failure", result["receipt_outcome"])
                self.assertFalse(result["has_tool_result"])
                serialized = result["serialized"]
                assert isinstance(serialized, str)
                self.assertNotIn("opaque", serialized)
                self.assertNotIn("secret", serialized)
                self.assertNotIn('"raw"', serialized)

    def test_legacy_tool_turn_shape_is_unchanged(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
    ],
);
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static fn (array $inputs): array => ['raw' => 'must-not-leak'],
    ]],
);

$result = AiConversationCore::turn(
    AiTenantContext::fromArray(
        [
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ],
        $policy,
    ),
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'inputs' => [],
        'request_ref' => 'request:legacy-bridge',
        'evidence_ref' => 'evidence:legacy-bridge',
        'timestamp' => '2026-10-04T10:00:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T10:00:00Z'),
    $registry,
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("completed", observed["status"])
        self.assertNotIn("tool_result", observed)
        self.assertNotIn("raw", json.dumps(observed))


if __name__ == "__main__":
    unittest.main()

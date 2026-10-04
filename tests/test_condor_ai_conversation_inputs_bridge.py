#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "src/Application/AI/AiConversationCore.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiConversationInputsBridgeTests(unittest.TestCase):
    def test_tool_turn_delivers_only_descriptor_validated_inputs(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref', 'limit'],
        'required_inputs' => ['category_ref'],
    ],
);
$seen = null;
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static function (array $inputs) use (&$seen): array {
            $seen = $inputs;
            return ['opaque' => 'not-exposed'];
        },
    ]],
);
$result = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'inputs' => [
            'category_ref' => 'category:industrial',
            'limit' => 12,
        ],
        'request_ref' => 'request:inputs-bridge',
        'evidence_ref' => 'evidence:inputs-bridge',
        'timestamp' => '2026-10-04T08:30:00+00:00',
    ],
    [],
    new DateTimeImmutable('2026-10-04T08:30:00Z'),
    $registry,
);

print json_encode([
    'seen' => $seen,
    'result' => $result,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {"category_ref": "category:industrial", "limit": 12},
            observed["seen"],
        )
        result = observed["result"]
        self.assertEqual("completed", result["status"])
        self.assertTrue(result["executed"])
        encoded = json.dumps(result, sort_keys=True)
        self.assertNotIn("category:industrial", encoded)
        self.assertNotIn('"limit": 12', encoded)
        self.assertNotIn("opaque", encoded)

        source = CORE.read_text(encoding="utf-8")
        self.assertIn("'inputs'", source)
        self.assertIn("resolveWithInputs(", source)
        self.assertIn("$registration['descriptor']", source)
        self.assertIn("$registration['inputs']", source)
        self.assertIn("'version' => '0.1.158'", VERSION.read_text(encoding="utf-8"))

    def test_invalid_inputs_handoff_before_handler_and_never_leak(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref'],
        'required_inputs' => ['category_ref'],
    ],
);
$executions = 0;
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static function (array $inputs) use (&$executions): void {
            ++$executions;
        },
    ]],
);
$at = new DateTimeImmutable('2026-10-04T08:30:00Z');
$base = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'request_ref' => 'request:invalid-inputs',
    'evidence_ref' => 'evidence:invalid-inputs',
    'timestamp' => '2026-10-04T08:30:00+00:00',
];

$missingRequired = $base + ['inputs' => []];
$unknown = $base + [
    'inputs' => [
        'category_ref' => 'category:a',
        'unknown' => 'secret-value',
    ],
];
$missingShape = $base;

$out = [
    'missing_required' => AiConversationCore::turn(
        $context, $policy, $missingRequired, [], $at, $registry
    ),
    'unknown' => AiConversationCore::turn(
        $context, $policy, $unknown, [], $at, $registry
    ),
    'missing_shape' => AiConversationCore::turn(
        $context, $policy, $missingShape, [], $at, $registry
    ),
    'executions' => $executions,
];

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(0, observed["executions"])
        self.assertEqual("handoff", observed["missing_required"]["status"])
        self.assertEqual("tool_inputs_invalid", observed["missing_required"]["reason"])
        self.assertEqual("handoff", observed["unknown"]["status"])
        self.assertEqual("tool_inputs_invalid", observed["unknown"]["reason"])
        self.assertEqual("handoff", observed["missing_shape"]["status"])
        self.assertEqual("turn_not_canonical", observed["missing_shape"]["reason"])
        encoded = json.dumps(observed, sort_keys=True)
        self.assertNotIn("secret-value", encoded)


if __name__ == "__main__":
    unittest.main()

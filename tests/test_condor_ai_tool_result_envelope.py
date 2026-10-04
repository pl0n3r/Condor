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


class CondorAiToolResultEnvelopeTests(unittest.TestCase):
    def test_handler_result_is_minimized_by_descriptor_before_return(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
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

$result = AiToolInvocation::invoke(
    $context,
    $policy,
    'tenant-a',
    'catalog.read',
    'evidence:tool-result',
    '2026-10-04T09:30:00+00:00',
    static fn (array $inputs): array => [
        'product_ref' => 'product:42',
        'label' => 'Industrial',
    ],
    $descriptor,
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("success", observed["outcome"])
        self.assertTrue(observed["executed"])
        self.assertEqual(
            {"product_ref": "product:42", "label": "Industrial"},
            observed["tool_result"],
        )
        audit = observed["audit"]
        assert isinstance(audit, dict)
        self.assertNotIn("product_ref", audit)
        self.assertNotIn("label", audit)
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
    'unknown' => ['product_ref' => 'product:42', 'unknown' => 'secret'],
    'nested' => ['product_ref' => ['raw' => 'opaque']],
    'non_array' => 'raw-handler-result',
];

$out = [];
foreach ($cases as $name => $handlerResult) {
    $result = AiToolInvocation::invoke(
        $context,
        $policy,
        'tenant-a',
        'catalog.read',
        'evidence:invalid-result',
        '2026-10-04T09:30:00+00:00',
        static fn (array $inputs): mixed => $handlerResult,
        $descriptor,
    );

    $out[$name] = [
        'outcome' => $result['outcome'],
        'executed' => $result['executed'],
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
                self.assertEqual("failure", result["outcome"])
                self.assertTrue(result["executed"])
                self.assertFalse(result["has_tool_result"])
                serialized = result["serialized"]
                assert isinstance(serialized, str)
                self.assertNotIn("secret", serialized)
                self.assertNotIn("opaque", serialized)
                self.assertNotIn("raw-handler-result", serialized)

    def test_legacy_descriptor_ignores_handler_return(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
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
    ],
);

$result = AiToolInvocation::invoke(
    $context,
    $policy,
    'tenant-a',
    'catalog.read',
    'evidence:legacy-result',
    '2026-10-04T09:30:00+00:00',
    static fn (array $inputs): array => ['raw' => 'must-not-leak'],
    $descriptor,
);

print json_encode($result, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("success", observed["outcome"])
        self.assertTrue(observed["executed"])
        self.assertNotIn("tool_result", observed)
        self.assertNotIn("raw", json.dumps(observed))


if __name__ == "__main__":
    unittest.main()

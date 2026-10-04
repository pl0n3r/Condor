#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/AI/AiToolInvocation.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiToolInvocationInputsTests(unittest.TestCase):
    def test_allowed_inputs_reach_handler_without_leaking_into_audit(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

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
$result = AiToolInvocation::invoke(
    $context,
    $policy,
    'tenant-a',
    'catalog.read',
    'evidence:catalog-inputs',
    '2026-10-04T08:30:00+00:00',
    static function (array $inputs) use (&$seen): void {
        $seen = $inputs;
    },
    $descriptor,
    [
        'category_ref' => 'category:industrial',
        'limit' => 12,
    ],
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
        assert isinstance(result, dict)
        self.assertEqual("success", result["outcome"])
        self.assertTrue(result["executed"])
        self.assertNotIn("inputs", result)
        self.assertNotIn("category_ref", result["audit"])
        self.assertNotIn("limit", result["audit"])

        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("AiToolDescriptor $descriptor", source)
        self.assertIn("$descriptor->validateInputs($inputs)", source)
        self.assertIn("'version' => '0.1.156'", VERSION.read_text(encoding="utf-8"))

    def test_unknown_or_missing_inputs_fail_closed_before_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolInvocation;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$catalog = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref', 'limit'],
        'required_inputs' => ['category_ref'],
    ],
);
$inventory = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'inventory.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['sku_ref'],
        'required_inputs' => [],
    ],
);

$cases = [
    'missing_required' => [$catalog, []],
    'unknown_input' => [$catalog, ['category_ref' => 'category:a', 'unknown' => true]],
    'descriptor_mismatch' => [$inventory, []],
    'inputs_without_descriptor' => [null, ['category_ref' => 'category:a']],
];

$out = [];
foreach ($cases as $name => [$descriptor, $inputs]) {
    $executions = 0;
    try {
        AiToolInvocation::invoke(
            $context,
            $policy,
            'tenant-a',
            'catalog.read',
            'evidence:invalid-inputs',
            '2026-10-04T08:30:00+00:00',
            static function (array $validated = []) use (&$executions): void {
                ++$executions;
            },
            $descriptor,
            $inputs,
        );
        $out[$name] = ['failed_closed' => false, 'executions' => $executions];
    } catch (DomainException) {
        $out[$name] = ['failed_closed' => true, 'executions' => $executions];
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        for name, result in observed.items():
            with self.subTest(case=name):
                assert isinstance(result, dict)
                self.assertTrue(result["failed_closed"])
                self.assertEqual(0, result["executions"])


if __name__ == "__main__":
    unittest.main()

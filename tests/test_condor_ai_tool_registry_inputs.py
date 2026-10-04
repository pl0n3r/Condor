#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REGISTRY = ROOT / "src/Application/AI/AiToolRegistry.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiToolRegistryInputsTests(unittest.TestCase):
    def test_registry_returns_only_validated_input_binding(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref', 'limit'],
        'required_inputs' => ['category_ref'],
    ],
);
$executions = 0;
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static function (array $inputs) use (&$executions): array {
            ++$executions;
            return $inputs;
        },
    ]],
);
$binding = $registry->resolveWithInputs(
    'catalog.read',
    [
        'category_ref' => 'category:industrial',
        'limit' => 12,
    ],
);

print json_encode([
    'descriptor' => $binding['descriptor']->snapshot(),
    'inputs' => $binding['inputs'],
    'handler_is_closure' => $binding['handler'] instanceof Closure,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("catalog.read", observed["descriptor"]["tool_ref"])
        self.assertEqual(
            {"category_ref": "category:industrial", "limit": 12},
            observed["inputs"],
        )
        self.assertTrue(observed["handler_is_closure"])
        self.assertEqual(0, observed["executions"])

        source = REGISTRY.read_text(encoding="utf-8")
        self.assertIn("resolveWithInputs(string $tool, array $inputs)", source)
        self.assertIn("$descriptor->validateInputs($inputs)", source)

    def test_registry_rejects_missing_unknown_or_tool_mismatch_without_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref', 'limit'],
        'required_inputs' => ['category_ref'],
    ],
);
$executions = 0;
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => static function (array $inputs = []) use (&$executions): void {
            ++$executions;
        },
    ]],
);

$cases = [
    'missing_required' => ['catalog.read', []],
    'unknown_input' => ['catalog.read', ['category_ref' => 'category:a', 'unknown' => true]],
    'unknown_tool' => ['inventory.read', ['category_ref' => 'category:a']],
    'noncanonical_tool' => ['Catalog.Read', ['category_ref' => 'category:a']],
];

$out = [];
foreach ($cases as $name => [$tool, $inputs]) {
    try {
        $registry->resolveWithInputs($tool, $inputs);
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
                self.assertTrue(result["failed_closed"])
                self.assertEqual(0, result["executions"])


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DESCRIPTOR = ROOT / "src/Domain/AI/AiToolDescriptor.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiToolOutputDescriptorTests(unittest.TestCase):
    def test_descriptor_binds_canonical_minimized_output_contract(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$legacy = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
    ],
);
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref'],
        'required_inputs' => [],
        'output_names' => ['product_ref', 'label', 'price_ref'],
        'required_outputs' => ['product_ref'],
    ],
);
$legacy->validateOutputs([]);
$descriptor->validateOutputs([
    'product_ref' => 'product:42',
    'label' => 'Industrial',
]);

print json_encode([
    'legacy_output_names' => $legacy->outputNames(),
    'legacy_required_outputs' => $legacy->requiredOutputs(),
    'output_names' => $descriptor->outputNames(),
    'required_outputs' => $descriptor->requiredOutputs(),
    'snapshot' => $descriptor->snapshot(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual([], observed["legacy_output_names"])
        self.assertEqual([], observed["legacy_required_outputs"])
        self.assertEqual(
            ["label", "price_ref", "product_ref"],
            observed["output_names"],
        )
        self.assertEqual(["product_ref"], observed["required_outputs"])

        snapshot = observed["snapshot"]
        assert isinstance(snapshot, dict)
        self.assertEqual("catalog.read", snapshot["tool_ref"])

        source = DESCRIPTOR.read_text(encoding="utf-8")
use App\Domain\AI\AiToolPolicy;

$descriptor = AiToolDescriptor::fromArray(
    new AiToolPolicy(),
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
        'output_names' => [
            'bool_value',
            'float_value',
            'int_value',
            'null_value',
            'string_value',
        ],
        'required_outputs' => ['string_value'],
    ],
);

$descriptor->validateOutputs([
    'string_value' => 'product:42',
    'int_value' => 42,
    'float_value' => 19.5,
    'bool_value' => true,
    'null_value' => null,
]);

print json_encode(['accepted' => true], JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["accepted"])

    def test_nested_array_object_or_resource_output_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$descriptor = AiToolDescriptor::fromArray(
    new AiToolPolicy(),
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
        'output_names' => ['value'],
        'required_outputs' => ['value'],
    ],
);

$resource = fopen('php://memory', 'r');
$cases = [
    'nested_array' => ['raw' => 'opaque'],
    'object' => new stdClass(),
    'resource' => $resource,
    'non_finite_float' => INF,
];

$out = [];
foreach ($cases as $name => $value) {
    try {
        $descriptor->validateOutputs(['value' => $value]);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}
fclose($resource);

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed)
        for name, failed_closed in observed.items():
            with self.subTest(case=name):
                self.assertTrue(failed_closed)

    def test_legacy_descriptor_keeps_empty_output_contract(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$descriptor = AiToolDescriptor::fromArray(
    new AiToolPolicy(),
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => [],
        'required_inputs' => [],
    ],
);

$descriptor->validateOutputs([]);
$rejected = false;
try {
    $descriptor->validateOutputs(['value' => 'unexpected']);
} catch (DomainException) {
    $rejected = true;
}

print json_encode(
    [
        'empty_accepted' => true,
        'nonempty_rejected' => $rejected,
    ],
    JSON_THROW_ON_ERROR,
);
"""
        )

        self.assertTrue(observed["empty_accepted"])
        self.assertTrue(observed["nonempty_rejected"])

    def test_unknown_forbidden_or_missing_output_contract_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$base = [
    'tool' => 'catalog.read',
    'risk' => AiToolPolicy::READ_ONLY,
    'input_names' => [],
    'required_inputs' => [],
    'output_names' => ['label', 'product_ref'],
    'required_outputs' => ['product_ref'],
];

$descriptorCases = [
    'duplicate' => array_replace($base, ['output_names' => ['label', 'label']]),
    'forbidden' => array_replace($base, ['output_names' => ['raw']]),
    'noncanonical' => array_replace($base, ['output_names' => ['Invalid-Key']]),
    'required_outside_allowlist' => array_replace(
        $base,
        ['required_outputs' => ['missing_ref']],
    ),
];
$partial = $base;
unset($partial['required_outputs']);
$descriptorCases['partial_contract'] = $partial;

$out = [];
foreach ($descriptorCases as $name => $case) {
    try {
        AiToolDescriptor::fromArray($policy, $case);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

$descriptor = AiToolDescriptor::fromArray($policy, $base);
$outputCases = [
    'missing_required' => [],
    'unknown_output' => [
        'product_ref' => 'product:42',
        'unknown' => 'x',
    ],
    'forbidden_output' => [
        'product_ref' => 'product:42',
        'raw' => 'opaque',
    ],
];
foreach ($outputCases as $name => $outputs) {
    try {
        $descriptor->validateOutputs($outputs);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed)
        for name, failed_closed in observed.items():
            with self.subTest(case=name):
                self.assertTrue(failed_closed)


if __name__ == "__main__":
    unittest.main()

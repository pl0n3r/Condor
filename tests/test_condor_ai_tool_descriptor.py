#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiToolDescriptor.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiToolDescriptorTests(unittest.TestCase):
    def test_descriptor_is_canonical_minimized_and_offline(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'content.draft.update',
        'risk' => AiToolPolicy::REVERSIBLE_WRITE,
        'input_names' => ['title', 'draft_id', 'content_ref'],
        'required_inputs' => ['draft_id'],
    ],
);
$descriptor->validateInputs([
    'draft_id' => 'draft:42',
    'content_ref' => 'content:7',
]);

print json_encode([
    'tool' => $descriptor->tool(),
    'risk' => $descriptor->risk(),
    'input_names' => $descriptor->inputNames(),
    'required_inputs' => $descriptor->requiredInputs(),
    'snapshot' => $descriptor->snapshot(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("content.draft.update", observed["tool"])
        self.assertEqual("reversible_write", observed["risk"])
        self.assertEqual(
            ["content_ref", "draft_id", "title"],
            observed["input_names"],
        )
        self.assertEqual(["draft_id"], observed["required_inputs"])
        self.assertEqual(
            {
                "tool_ref": "content.draft.update",
                "risk": "reversible_write",
                "input_names": ["content_ref", "draft_id", "title"],
                "required_inputs": ["draft_id"],
            },
            observed["snapshot"],
        )
        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo",
            "doctrine",
            "httpclient",
            "curl_",
            "file_get_contents(",
            "callable",
        ):
            self.assertNotIn(forbidden, source)

    def test_input_values_accept_only_scalar_or_null_contract(self) -> None:
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
        'input_names' => [
            'bool_value',
            'float_value',
            'int_value',
            'null_value',
            'string_value',
        ],
        'required_inputs' => ['string_value'],
    ],
);

$descriptor->validateInputs([
    'string_value' => 'category:industrial',
    'int_value' => 12,
    'float_value' => 19.5,
    'bool_value' => true,
    'null_value' => null,
]);

print json_encode(['accepted' => true], JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["accepted"])

    def test_nested_array_object_resource_or_non_finite_input_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$descriptor = AiToolDescriptor::fromArray(
    new AiToolPolicy(),
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['value'],
        'required_inputs' => ['value'],
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
        $descriptor->validateInputs(['value' => $value]);
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

    def test_unknown_risk_mismatch_or_noncanonical_descriptor_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$policy = new AiToolPolicy();
$base = [
    'tool' => 'catalog.read',
    'risk' => AiToolPolicy::READ_ONLY,
    'input_names' => ['category_ref', 'limit'],
    'required_inputs' => [],
];

$cases = [
    'unknown_tool' => array_replace($base, ['tool' => 'unknown.read']),
    'noncanonical_tool' => array_replace($base, ['tool' => ' Catalog.Read ']),
    'risk_mismatch' => array_replace($base, ['risk' => AiToolPolicy::REVERSIBLE_WRITE]),
    'duplicate_input' => array_replace($base, ['input_names' => ['limit', 'limit']]),
    'noncanonical_input' => array_replace($base, ['input_names' => ['Invalid-Key']]),
    'free_payload' => array_replace($base, ['input_names' => ['payload']]),
    'required_outside_allowlist' => array_replace($base, ['required_inputs' => ['missing_ref']]),
    'extra_shape' => $base + ['handler' => 'arbitrary'],
];

$out = [];
foreach ($cases as $name => $case) {
    try {
        AiToolDescriptor::fromArray($policy, $case);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

$descriptor = AiToolDescriptor::fromArray($policy, $base);
foreach (
    [
        'extra_input' => ['unknown' => 'x'],
        'forbidden_runtime_input' => ['payload' => 'opaque'],
        'missing_required' => [],
    ] as $name => $inputs
) {
    try {
        $requiredDescriptor = $name === 'missing_required'
            ? AiToolDescriptor::fromArray(
                $policy,
                [
                    'tool' => 'catalog.read',
                    'risk' => AiToolPolicy::READ_ONLY,
                    'input_names' => ['category_ref'],
                    'required_inputs' => ['category_ref'],
                ],
            )
            : $descriptor;
        $requiredDescriptor->validateInputs($inputs);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(all(observed.values()), observed)


if __name__ == "__main__":
    unittest.main()

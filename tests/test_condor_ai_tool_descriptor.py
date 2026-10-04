#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiToolDescriptor.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiToolDescriptorTests(unittest.TestCase):
    def test_descriptor_binds_canonical_tool_risk_and_minimized_input_contract(self) -> None:
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
        self.assertIn("'version' => '0.1.152'", VERSION.read_text(encoding="utf-8"))

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

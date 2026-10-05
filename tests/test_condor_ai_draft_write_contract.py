#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiDraftWriteContract.php"
POLICY = ROOT / "src/Domain/AI/AiToolPolicy.php"


def run_php(body: str) -> dict[str, object]:
    prefix = r"""
require 'vendor/autoload.php';
use App\Domain\AI\AiDraftWriteContract;
use App\Domain\AI\AiToolPolicy;
$policy = new AiToolPolicy();
$before = $policy->allowlist();
$contract = new AiDraftWriteContract($policy);
"""
    raw = subprocess.check_output(
        ["php", "-r", prefix + body],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiDraftWriteContractTests(unittest.TestCase):
    def test_contract_reuses_only_existing_reversible_write_tools_without_policy_expansion(self) -> None:
        observed = run_php(
            r"""
$content = $contract->contentDescriptor();
$settings = $contract->settingsDescriptor();
print json_encode([
  'content' => [$content->tool(), $content->risk()],
  'settings' => [$settings->tool(), $settings->risk()],
  'policy_unchanged' => $before === $policy->allowlist(),
], JSON_THROW_ON_ERROR);
"""
        )
        self.assertEqual(["content.draft.update", "reversible_write"], observed["content"])
        self.assertEqual(["settings.draft.update", "reversible_write"], observed["settings"])
        self.assertTrue(observed["policy_unchanged"])
        policy = POLICY.read_text(encoding="utf-8")
        self.assertEqual(1, policy.count("'content.draft.update'"))
        self.assertEqual(1, policy.count("'settings.draft.update'"))

    def test_inputs_and_outputs_are_opaque_scalar_bounded_and_canonical(self) -> None:
        observed = run_php(
            r"""
$contract->validateContentInputs([
  'draft_ref' => 'content_draft:homepage',
  'change_ref' => 'change:hero_title',
  'expected_revision_ref' => 'revision:r42',
]);
$contract->validateContentOutputs([
  'draft_ref' => 'content_draft:homepage',
  'revision_ref' => 'revision:r43',
  'status' => 'updated',
]);
$contract->validateSettingsInputs([
  'draft_ref' => 'settings_draft:storefront',
  'change_ref' => 'change:currency',
]);
$contract->validateSettingsOutputs([
  'draft_ref' => 'settings_draft:storefront',
  'revision_ref' => 'revision:r10',
  'status' => 'conflict',
]);
print json_encode(['accepted' => true], JSON_THROW_ON_ERROR);
"""
        )
        self.assertTrue(observed["accepted"])

    def test_free_text_extra_cross_shape_or_incoherent_conflict_fails_closed(self) -> None:
        observed = run_php(
            r"""
$cases = [
  'cross_shape' => fn () => $contract->validateContentInputs([
    'draft_ref' => 'settings_draft:storefront', 'change_ref' => 'change:hero_title'
  ]),
  'free_text' => fn () => $contract->validateContentInputs([
    'draft_ref' => 'content_draft:homepage', 'change_ref' => 'hero title libre'
  ]),
  'extra_input' => fn () => $contract->validateContentInputs([
    'draft_ref' => 'content_draft:homepage', 'change_ref' => 'change:hero_title',
    'text' => 'contenido libre'
  ]),
  'revision' => fn () => $contract->validateSettingsInputs([
    'draft_ref' => 'settings_draft:storefront', 'change_ref' => 'change:currency',
    'expected_revision_ref' => 'r10'
  ]),
  'extra_output' => fn () => $contract->validateContentOutputs([
    'draft_ref' => 'content_draft:homepage', 'revision_ref' => 'revision:r43',
    'status' => 'updated', 'applied' => true
  ]),
  'conflict_revision' => fn () => $contract->validateSettingsOutputs([
    'draft_ref' => 'settings_draft:storefront',
    'revision_ref' => 'settings_draft:storefront',
    'status' => 'conflict'
  ]),
  'status' => fn () => $contract->validateSettingsOutputs([
    'draft_ref' => 'settings_draft:storefront', 'revision_ref' => 'revision:r10',
    'status' => 'pending'
  ]),
];
$out = [];
foreach ($cases as $name => $case) {
  try { $case(); $out[$name] = false; }
  catch (DomainException) { $out[$name] = true; }
}
print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )
        self.assertTrue(all(observed.values()), observed)

    def test_contract_has_no_io_provider_channel_or_sensitive_payload(self) -> None:
        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo", "doctrine", "httpclient", "curl_", "file_get_contents(",
            "fopen(", "provider", "model", "channel", "secret", "token",
            "credential", "prompt", "transcript", "payload",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

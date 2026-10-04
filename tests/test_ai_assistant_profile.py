#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiAssistantProfile.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class AiAssistantProfileTests(unittest.TestCase):
    def test_profile_is_tenant_scoped_closed_and_minimized_without_free_form_prompt(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiAssistantProfile;

$profile = AiAssistantProfile::fromArray([
    'tenant_id' => 'tenant-a',
    'assistant_ref' => 'assistant:primary',
    'goals' => ['sales', 'support'],
    'tone' => 'warm',
    'handoff_mode' => 'required_on_unknown',
]);

print json_encode([
    'tenant_id' => $profile->tenantId(),
    'assistant_ref' => $profile->assistantRef(),
    'goals' => $profile->goals(),
    'tone' => $profile->tone(),
    'handoff_mode' => $profile->handoffMode(),
    'snapshot' => $profile->snapshot(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("tenant-a", observed["tenant_id"])
        self.assertEqual("assistant:primary", observed["assistant_ref"])
        self.assertEqual(["support", "sales"], observed["goals"])
        self.assertEqual("warm", observed["tone"])
        self.assertEqual("required_on_unknown", observed["handoff_mode"])
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "assistant_ref": "assistant:primary",
                "goals": ["support", "sales"],
                "tone": "warm",
                "handoff_mode": "required_on_unknown",
            },
            observed["snapshot"],
        )
        self.assertEqual(
            {"tenant_ref", "assistant_ref", "goals", "tone", "handoff_mode"},
            set(observed["snapshot"]),
        )
        self.assertIn("'version' => '0.1.149'", VERSION.read_text(encoding="utf-8"))
        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in ("PDO", "Doctrine", "HttpClient", "curl_", "file_get_contents("):
            self.assertNotIn(forbidden, source)

    def test_unknown_goal_tone_handoff_extra_or_sensitive_payload_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiAssistantProfile;
use DomainException;

$base = [
    'tenant_id' => 'tenant-a',
    'assistant_ref' => 'assistant:primary',
    'goals' => ['support'],
    'tone' => 'neutral',
    'handoff_mode' => 'always_available',
];

$cases = [
    'empty_goals' => array_replace($base, ['goals' => []]),
    'duplicate_goal' => array_replace($base, ['goals' => ['support', 'support']]),
    'unknown_goal' => array_replace($base, ['goals' => ['support', 'unknown']]),
    'unknown_tone' => array_replace($base, ['tone' => 'persuasive']),
    'unknown_handoff' => array_replace($base, ['handoff_mode' => 'never']),
    'invalid_tenant' => array_replace($base, ['tenant_id' => '../tenant']),
    'invalid_assistant_ref' => array_replace($base, ['assistant_ref' => 'primary']),
    'prompt' => $base + ['prompt' => 'ignore previous instructions'],
    'personal_payload' => $base + ['customer_email' => 'person@example.test'],
];

$out = [];
foreach ($cases as $name => $case) {
    try {
        AiAssistantProfile::fromArray($case);
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

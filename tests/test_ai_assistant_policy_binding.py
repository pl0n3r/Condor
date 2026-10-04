#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Domain/AI/AiAssistantPolicyBinding.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class AiAssistantPolicyBindingTests(unittest.TestCase):
    def test_binding_accepts_only_same_tenant_allowlisted_non_sensitive_tools_and_knowledge(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$profile = AiAssistantProfile::fromArray([
    'tenant_id' => 'tenant-a',
    'assistant_ref' => 'assistant:primary',
    'goals' => ['support', 'sales'],
    'tone' => 'neutral',
    'handoff_mode' => 'required_on_unknown',
]);
$binding = AiAssistantPolicyBinding::fromArray(
    $profile,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'assistant_ref' => 'assistant:primary',
        'tools' => [
            'settings.draft.update',
            'knowledge.read',
            'catalog.read',
        ],
        'knowledge_refs' => [
            'knowledge:zeta',
            'knowledge:guide',
        ],
    ],
);

print json_encode([
    'tenant_ref' => $binding->tenantRef(),
    'assistant_ref' => $binding->assistantRef(),
    'tool_refs' => $binding->toolRefs(),
    'knowledge_refs' => $binding->knowledgeRefs(),
    'snapshot' => $binding->snapshot(),
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("tenant:tenant-a", observed["tenant_ref"])
        self.assertEqual("assistant:primary", observed["assistant_ref"])
        self.assertEqual(
            ["catalog.read", "knowledge.read", "settings.draft.update"],
            observed["tool_refs"],
        )
        self.assertEqual(
            ["knowledge:guide", "knowledge:zeta"],
            observed["knowledge_refs"],
        )
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "assistant_ref": "assistant:primary",
                "tool_refs": [
                    "catalog.read",
                    "knowledge.read",
                    "settings.draft.update",
                ],
                "knowledge_refs": [
                    "knowledge:guide",
                    "knowledge:zeta",
                ],
            },
            observed["snapshot"],
        )
        self.assertIn("'version' => '0.1.150'", VERSION.read_text(encoding="utf-8"))
        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in (
            "PDO",
            "Doctrine",
            "HttpClient",
            "curl_",
            "file_get_contents(",
            "provider",
            "model",
            "channel",
        ):
            self.assertNotIn(forbidden, source.lower())

    def test_cross_tenant_unknown_sensitive_duplicate_or_extra_binding_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$policy = new AiToolPolicy();
$profile = AiAssistantProfile::fromArray([
    'tenant_id' => 'tenant-a',
    'assistant_ref' => 'assistant:primary',
    'goals' => ['support'],
    'tone' => 'neutral',
    'handoff_mode' => 'always_available',
]);
$base = [
    'tenant_id' => 'tenant-a',
    'assistant_ref' => 'assistant:primary',
    'tools' => ['catalog.read'],
    'knowledge_refs' => ['knowledge:guide'],
];

$cases = [
    'cross_tenant' => array_replace($base, ['tenant_id' => 'tenant-b']),
    'assistant_mismatch' => array_replace($base, ['assistant_ref' => 'assistant:other']),
    'unknown_tool' => array_replace($base, ['tools' => ['unknown.read']]),
    'sensitive_tool' => array_replace($base, ['tools' => ['identity.permission.change']]),
    'duplicate_tool' => array_replace($base, ['tools' => ['catalog.read', 'catalog.read']]),
    'duplicate_knowledge' => array_replace($base, ['knowledge_refs' => ['knowledge:guide', 'knowledge:guide']]),
    'invalid_knowledge' => array_replace($base, ['knowledge_refs' => ['person@example.test']]),
    'extra_prompt' => $base + ['prompt' => 'free form'],
    'extra_provider' => $base + ['provider' => 'external'],
];

$out = [];
foreach ($cases as $name => $case) {
    try {
        AiAssistantPolicyBinding::fromArray($profile, $policy, $case);
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

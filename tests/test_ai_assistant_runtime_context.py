#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/AI/AiAssistantRuntimeContext.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class AiAssistantRuntimeContextTests(unittest.TestCase):
    def test_bound_tool_resolves_existing_tenant_context_with_exact_knowledge_scope(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiAssistantRuntimeContext;
use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$profile = AiAssistantProfile::fromArray([
    'tenant_id' => 'tenant-a',
    'assistant_ref' => 'assistant:primary',
    'goals' => ['support'],
    'tone' => 'neutral',
    'handoff_mode' => 'required_on_unknown',
]);
$binding = AiAssistantPolicyBinding::fromArray(
    $profile,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'assistant_ref' => 'assistant:primary',
        'tools' => ['catalog.read', 'knowledge.read'],
        'knowledge_refs' => ['knowledge:returns', 'knowledge:guide'],
    ],
);
$context = AiAssistantRuntimeContext::resolve(
    $profile,
    $binding,
    $policy,
    ' KNOWLEDGE.READ ',
);

print json_encode($context->snapshot(), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            {
                "tenant_id": "tenant-a",
                "tool": "knowledge.read",
                "knowledge_refs": ["knowledge:guide", "knowledge:returns"],
            },
            observed,
        )
        self.assertIn("'version' => '0.1.151'", VERSION.read_text(encoding="utf-8"))
        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in (
            "AiConversationCore",
            "AiToolInvocation",
            "Executor",
            "PDO",
            "Doctrine",
            "HttpClient",
            "curl_",
            "file_get_contents(",
        ):
            self.assertNotIn(forbidden, source)

    def test_unbound_sensitive_cross_tenant_or_incoherent_context_fails_closed_without_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiAssistantRuntimeContext;
use App\Domain\AI\AiAssistantPolicyBinding;
use App\Domain\AI\AiAssistantProfile;
use App\Domain\AI\AiToolPolicy;
use DomainException;

function profile(string $tenantId, string $assistantRef): AiAssistantProfile {
    return AiAssistantProfile::fromArray([
        'tenant_id' => $tenantId,
        'assistant_ref' => $assistantRef,
        'goals' => ['support'],
        'tone' => 'neutral',
        'handoff_mode' => 'required_on_unknown',
    ]);
}

$policy = new AiToolPolicy();
$profile = profile('tenant-a', 'assistant:primary');
$binding = AiAssistantPolicyBinding::fromArray(
    $profile,
    $policy,
    [
        'tenant_id' => 'tenant-a',
        'assistant_ref' => 'assistant:primary',
        'tools' => ['catalog.read', 'knowledge.read'],
        'knowledge_refs' => ['knowledge:returns', 'knowledge:guide'],
    ],
);

$cases = [
    'unbound' => [profile('tenant-a', 'assistant:primary'), 'inventory.read'],
    'sensitive' => [profile('tenant-a', 'assistant:primary'), 'identity.permission.change'],
    'cross_tenant' => [profile('tenant-b', 'assistant:primary'), 'knowledge.read'],
    'incoherent_assistant' => [profile('tenant-a', 'assistant:other'), 'knowledge.read'],
    'unknown' => [profile('tenant-a', 'assistant:primary'), 'unknown.read'],
];

$out = [];
foreach ($cases as $name => [$candidateProfile, $tool]) {
    try {
        AiAssistantRuntimeContext::resolve(
            $candidateProfile,
            $binding,
            $policy,
            $tool,
        );
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

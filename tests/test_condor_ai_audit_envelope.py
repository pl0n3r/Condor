#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUTOLOAD = ROOT / "vendor/autoload.php"
SOURCE = ROOT / "src/Domain/AI/AiAuditEnvelope.php"
VERSION = ROOT / "config/version.php"


class CondorAiAuditEnvelopeTests(unittest.TestCase):
    def run_php(self, script: str) -> None:
        self.assertTrue(AUTOLOAD.exists(), "vendor/autoload.php no está disponible")
        result = subprocess.run(
            ["php", "-r", script, str(AUTOLOAD)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_envelope_keeps_only_tenant_tool_outcome_evidence_and_timestamp(self) -> None:
        self.run_php(
            r'''
require $argv[1];
$policy = new \App\Domain\AI\AiToolPolicy();
$envelope = \App\Domain\AI\AiAuditEnvelope::fromArray([
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'outcome' => 'denied',
    'evidence_ref' => 'evidence:decision-42',
    'timestamp' => '2026-10-03T01:00:00+00:00',
], $policy);

$expected = [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'identity.permission.change',
    'outcome' => 'denied',
    'evidence_ref' => 'evidence:decision-42',
    'timestamp' => '2026-10-03T01:00:00+00:00',
];
if ($envelope->snapshot() !== $expected) { throw new \RuntimeException('snapshot'); }
if ($envelope->tenantRef() !== 'tenant:tenant-a') { throw new \RuntimeException('tenant'); }
if ($envelope->toolRef() !== 'identity.permission.change') { throw new \RuntimeException('tool'); }
if ($envelope->outcome() !== 'denied') { throw new \RuntimeException('outcome'); }
if ($envelope->evidenceRef() !== 'evidence:decision-42') { throw new \RuntimeException('evidence'); }
if ($envelope->timestamp() !== '2026-10-03T01:00:00+00:00') { throw new \RuntimeException('timestamp'); }
'''
        )

        source = SOURCE.read_text(encoding="utf-8")
        for forbidden_dependency in (
            "Doctrine\\",
            "Symfony\\",
            "PDO",
            "HttpClient",
            "curl_",
            "file_get_contents(",
            "fopen(",
        ):
            self.assertNotIn(forbidden_dependency, source)

        self.assertIn(
            "'version' => '0.1.121'",
            VERSION.read_text(encoding="utf-8"),
        )

    def test_prompt_pii_secrets_and_free_form_payload_fail_closed(self) -> None:
        self.run_php(
            r'''
require $argv[1];
$policy = new \App\Domain\AI\AiToolPolicy();
$base = [
    'tenant_ref' => 'tenant:tenant-a',
    'tool_ref' => 'knowledge.read',
    'outcome' => 'success',
    'evidence_ref' => 'evidence:article-42',
    'timestamp' => '2026-10-03T01:00:00+00:00',
];

$expectDomainException = static function (callable $callback): void {
    try {
        $callback();
        throw new \RuntimeException('expected DomainException');
    } catch (\DomainException) {
    }
};

foreach (
    ['prompt', 'conversation', 'email', 'phone', 'token', 'secret', 'raw_input', 'raw_output', 'payload']
    as $field
) {
    $input = $base;
    $input[$field] = 'forbidden';
    $expectDomainException(
        fn () => \App\Domain\AI\AiAuditEnvelope::fromArray($input, $policy)
    );
}

foreach (
    [
        ['tenant_ref' => 'tenant:user@example.com'],
        ['tool_ref' => 'provider.openai'],
        ['outcome' => 'maybe'],
        ['evidence_ref' => 'evidence:user@example.com'],
        ['timestamp' => 'tomorrow'],
    ]
    as $override
) {
    $input = array_replace($base, $override);
    $expectDomainException(
        fn () => \App\Domain\AI\AiAuditEnvelope::fromArray($input, $policy)
    );
}
'''
        )


if __name__ == "__main__":
    unittest.main()

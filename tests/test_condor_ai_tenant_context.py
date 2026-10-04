#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUTOLOAD = ROOT / "vendor/autoload.php"
SOURCE = ROOT / "src/Domain/AI/AiTenantContext.php"


class CondorAiTenantContextTests(unittest.TestCase):
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

    def test_context_requires_single_tenant_and_authorized_tool_reference(self) -> None:
        self.run_php(
            r'''
require $argv[1];
$policy = new \App\Domain\AI\AiToolPolicy();
$context = \App\Domain\AI\AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'knowledge.read',
    'knowledge_refs' => ['knowledge:article-42', 'knowledge:article-99'],
], $policy);
if ($context->tenantId() !== 'tenant-a') { throw new \RuntimeException('tenant'); }
if ($context->tool() !== 'knowledge.read') { throw new \RuntimeException('tool'); }
if ($context->knowledgeRefs() !== ['knowledge:article-42', 'knowledge:article-99']) { throw new \RuntimeException('refs'); }
if ($context->snapshot() !== [
    'tenant_id' => 'tenant-a',
    'tool' => 'knowledge.read',
    'knowledge_refs' => ['knowledge:article-42', 'knowledge:article-99'],
]) { throw new \RuntimeException('snapshot'); }
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
        ):
            self.assertNotIn(forbidden_dependency, source)


    def test_cross_tenant_rebind_or_personal_payload_fails_closed(self) -> None:
        self.run_php(
            r'''
require $argv[1];
$policy = new \App\Domain\AI\AiToolPolicy();
$context = \App\Domain\AI\AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);

$expectDomainException = static function (callable $callback): void {
    try {
        $callback();
        throw new \RuntimeException('expected DomainException');
    } catch (\DomainException) {
    }
};

$expectDomainException(fn () => $context->rebindTenant('tenant-b'));
$expectDomainException(fn () => \App\Domain\AI\AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'provider.openai',
    'knowledge_refs' => [],
], $policy));
$expectDomainException(fn () => \App\Domain\AI\AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => ['knowledge:user@example.com'],
], $policy));

foreach (['prompt', 'email', 'phone', 'token', 'payload'] as $field) {
    $input = [
        'tenant_id' => 'tenant-a',
        'tool' => 'catalog.read',
        'knowledge_refs' => [],
        $field => 'forbidden',
    ];
    $expectDomainException(
        fn () => \App\Domain\AI\AiTenantContext::fromArray($input, $policy)
    );
}
'''
        )


if __name__ == "__main__":
    unittest.main()

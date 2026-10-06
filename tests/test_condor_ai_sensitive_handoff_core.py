#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/condor-ai-sensitive-handoff-v1.md"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiSensitiveHandoffCoreTests(unittest.TestCase):
    def test_sensitive_tool_returns_minimized_human_handoff_and_never_executes_registry_or_handler(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$descriptor = AiToolDescriptor::fromArray($policy, [
    'tool' => 'identity.permission.change',
    'risk' => AiToolPolicy::SENSITIVE,
    'input_names' => [],
    'required_inputs' => [],
]);
$executions = 0;
$registry = AiToolRegistry::fromArray($policy, [[
    'tool' => 'identity.permission.change',
    'descriptor' => $descriptor,
    'handler' => static function (array $inputs) use (&$executions): array {
        ++$executions;
        return [];
    },
]]);

$result = AiConversationCore::turn(
    $context,
    $policy,
    [
        'intent' => 'tool',
        'tenant_id' => 'tenant-a',
        'tool' => 'identity.permission.change',
        'inputs' => ['unexpected' => 'must-not-be-validated'],
        'request_ref' => 'request:permission-review-core-001',
        'evidence_ref' => 'evidence:permission-review-core-001',
        'timestamp' => '2026-10-05T20:00:00+00:00',
    ],
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $registry,
);

print json_encode([
    'result' => $result,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        result = observed["result"]
        self.assertEqual("handoff", result["status"])
        self.assertEqual("tool", result["route"])
        self.assertEqual("tool_sensitive_requires_human", result["reason"])
        self.assertFalse(result["executed"])
        self.assertEqual([], result["evidence_refs"])
        self.assertIsNone(result["audit"])
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "route": "tool",
                "reason": "tool_sensitive_requires_human",
                "evidence_refs": [],
            },
            result["handoff"],
        )
        self.assertEqual(
            {
                "tenant_ref": "tenant:tenant-a",
                "tool_ref": "identity.permission.change",
                "request_ref": "request:permission-review-core-001",
                "evidence_ref": "evidence:permission-review-core-001",
            },
            result["sensitive_request"],
        )
        self.assertNotIn("receipt", result)
        self.assertNotIn("tool_result", result)
        self.assertEqual(0, observed["executions"])

    def test_sensitive_path_never_claims_replay_or_invokes_tool_even_when_dependencies_are_present(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$descriptor = AiToolDescriptor::fromArray($policy, [
    'tool' => 'identity.permission.change',
    'risk' => AiToolPolicy::SENSITIVE,
    'input_names' => [],
    'required_inputs' => [],
]);
$executions = 0;
$registry = AiToolRegistry::fromArray($policy, [[
    'tool' => 'identity.permission.change',
    'descriptor' => $descriptor,
    'handler' => static function (array $inputs) use (&$executions): array {
        ++$executions;
        return [];
    },
]]);
$guard = new AiToolReplayGuard();
$turn = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'inputs' => [],
    'request_ref' => 'request:permission-review-core-replay',
    'evidence_ref' => 'evidence:permission-review-core-replay',
    'timestamp' => '2026-10-05T20:00:00+00:00',
];

$first = AiConversationCore::turn(
    $context,
    $policy,
    $turn,
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $registry,
    $guard,
);
$second = AiConversationCore::turn(
    $context,
    $policy,
    $turn,
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $registry,
    $guard,
);

$property = (new \ReflectionObject($guard))->getProperty('claims');
$property->setAccessible(true);

print json_encode([
    'first' => $first,
    'second' => $second,
    'claims' => $property->getValue($guard),
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("tool_sensitive_requires_human", observed["first"]["reason"])
        self.assertEqual("tool_sensitive_requires_human", observed["second"]["reason"])
        self.assertFalse(observed["first"]["executed"])
        self.assertFalse(observed["second"]["executed"])
        self.assertEqual([], observed["claims"])
        self.assertEqual(0, observed["executions"])

    def test_cross_tenant_invalid_refs_unknown_or_noncanonical_sensitive_turn_fails_closed_without_side_effects(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$context = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'knowledge_refs' => [],
], $policy);
$descriptor = AiToolDescriptor::fromArray($policy, [
    'tool' => 'identity.permission.change',
    'risk' => AiToolPolicy::SENSITIVE,
    'input_names' => [],
    'required_inputs' => [],
]);
$executions = 0;
$registry = AiToolRegistry::fromArray($policy, [[
    'tool' => 'identity.permission.change',
    'descriptor' => $descriptor,
    'handler' => static function (array $inputs) use (&$executions): array {
        ++$executions;
        return [];
    },
]]);
$guard = new AiToolReplayGuard();
$base = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'identity.permission.change',
    'inputs' => [],
    'request_ref' => 'request:permission-review-invalid-cases',
    'evidence_ref' => 'evidence:permission-review-invalid-cases',
    'timestamp' => '2026-10-05T20:00:00+00:00',
];
$cases = [
    'cross_tenant' => array_replace($base, ['tenant_id' => 'tenant-b']),
    'invalid_evidence' => array_replace($base, ['evidence_ref' => 'user@example.test']),
    'invalid_request' => array_replace($base, ['request_ref' => 'user@example.test']),
    'wrong_tool' => array_replace($base, ['tool' => 'catalog.read']),
    'unknown_tool' => array_replace($base, ['tool' => 'identity.permission.unknown']),
    'noncanonical' => array_merge($base, ['extra' => 'not-allowed']),
];

$out = [];
foreach ($cases as $name => $turn) {
    $result = AiConversationCore::turn(
        $context,
        $policy,
        $turn,
        [],
        new \DateTimeImmutable('2026-10-05T20:00:00Z'),
        $registry,
        $guard,
    );
    $out[$name] = [
        'status' => $result['status'],
        'route' => $result['route'],
        'reason' => $result['reason'],
        'executed' => $result['executed'],
        'has_sensitive_request' => array_key_exists('sensitive_request', $result),
    ];
}

$property = (new \ReflectionObject($guard))->getProperty('claims');
$property->setAccessible(true);

print json_encode([
    'cases' => $out,
    'claims' => $property->getValue($guard),
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        cases = observed["cases"]
        self.assertEqual(
            {
                "status": "handoff",
                "route": "none",
                "reason": "tenant_context_mismatch",
                "executed": False,
                "has_sensitive_request": False,
            },
            cases["cross_tenant"],
        )
        for name in ("invalid_evidence", "invalid_request", "wrong_tool", "unknown_tool"):
            with self.subTest(name=name):
                self.assertEqual("handoff", cases[name]["status"])
                self.assertEqual("tool", cases[name]["route"])
                self.assertEqual("tool_sensitive_handoff_invalid", cases[name]["reason"])
                self.assertFalse(cases[name]["executed"])
                self.assertFalse(cases[name]["has_sensitive_request"])

        self.assertEqual("handoff", cases["noncanonical"]["status"])
        self.assertEqual("tool", cases["noncanonical"]["route"])
        self.assertEqual("turn_not_canonical", cases["noncanonical"]["reason"])
        self.assertFalse(cases["noncanonical"]["executed"])
        self.assertFalse(cases["noncanonical"]["has_sensitive_request"])
        self.assertEqual([], observed["claims"])
        self.assertEqual(0, observed["executions"])

    def test_documented_boundary_contains_no_approval_execution_database_network_provider_real_data_or_live_path(self) -> None:
        documented = DOC.read_text(encoding="utf-8")

        for statement in (
            "La revisión humana no es una aprobación.",
            "No existe una ruta de ejecución posterior en este slice.",
            "No se registra ningún handler para la acción sensible.",
            "No se consulta ni escribe base de datos ni red.",
            "No se conecta ningún proveedor o modelo.",
            "No se usan datos reales ni PII.",
            "No activa despliegue ni go-live.",
        ):
            self.assertIn(statement, documented)

        for forbidden in (
            "approval_token",
            "replay_token",
            "permission_id",
            "actor_id",
            "user_id",
            "PDO(",
            "Doctrine\\",
            "HttpClient",
            "curl(",
            "http://",
            "https://",
            "/approve",
            "/execute",
            "customer_email",
        ):
            self.assertNotIn(forbidden, documented)

    def test_read_only_and_reversible_write_paths_preserve_existing_semantics(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$guard = new AiToolReplayGuard();

$readExecutions = 0;
$readDescriptor = AiToolDescriptor::fromArray($policy, [
    'tool' => 'catalog.read',
    'risk' => AiToolPolicy::READ_ONLY,
    'input_names' => [],
    'required_inputs' => [],
]);
$readRegistry = AiToolRegistry::fromArray($policy, [[
    'tool' => 'catalog.read',
    'descriptor' => $readDescriptor,
    'handler' => static function (array $inputs) use (&$readExecutions): array {
        ++$readExecutions;
        return [];
    },
]]);
$readContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'knowledge_refs' => [],
], $policy);
$readTurn = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'catalog.read',
    'inputs' => [],
    'request_ref' => 'request:read-core-semantics',
    'evidence_ref' => 'evidence:read-core-semantics',
    'timestamp' => '2026-10-05T20:00:00+00:00',
];
$readFirst = AiConversationCore::turn(
    $readContext,
    $policy,
    $readTurn,
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $readRegistry,
    $guard,
);
$readSecond = AiConversationCore::turn(
    $readContext,
    $policy,
    $readTurn,
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $readRegistry,
    $guard,
);

$writeExecutions = 0;
$writeDescriptor = AiToolDescriptor::fromArray($policy, [
    'tool' => 'content.draft.update',
    'risk' => AiToolPolicy::REVERSIBLE_WRITE,
    'input_names' => [],
    'required_inputs' => [],
]);
$writeRegistry = AiToolRegistry::fromArray($policy, [[
    'tool' => 'content.draft.update',
    'descriptor' => $writeDescriptor,
    'handler' => static function (array $inputs) use (&$writeExecutions): array {
        ++$writeExecutions;
        return [];
    },
]]);
$writeContext = AiTenantContext::fromArray([
    'tenant_id' => 'tenant-a',
    'tool' => 'content.draft.update',
    'knowledge_refs' => [],
], $policy);
$writeTurn = [
    'intent' => 'tool',
    'tenant_id' => 'tenant-a',
    'tool' => 'content.draft.update',
    'inputs' => [],
    'request_ref' => 'request:write-core-semantics',
    'evidence_ref' => 'evidence:write-core-semantics',
    'timestamp' => '2026-10-05T20:00:00+00:00',
];
$writeFirst = AiConversationCore::turn(
    $writeContext,
    $policy,
    $writeTurn,
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $writeRegistry,
    $guard,
);
$writeSecond = AiConversationCore::turn(
    $writeContext,
    $policy,
    $writeTurn,
    [],
    new \DateTimeImmutable('2026-10-05T20:00:00Z'),
    $writeRegistry,
    $guard,
);

print json_encode([
    'read_first' => $readFirst,
    'read_second' => $readSecond,
    'read_executions' => $readExecutions,
    'write_first' => $writeFirst,
    'write_second' => $writeSecond,
    'write_executions' => $writeExecutions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("completed", observed["read_first"]["status"])
        self.assertEqual("completed", observed["read_second"]["status"])
        self.assertTrue(observed["read_first"]["executed"])
        self.assertTrue(observed["read_second"]["executed"])
        self.assertEqual(2, observed["read_executions"])

        self.assertEqual("completed", observed["write_first"]["status"])
        self.assertTrue(observed["write_first"]["executed"])
        self.assertEqual("reversible_write", observed["write_first"]["receipt"]["risk"])
        self.assertEqual("handoff", observed["write_second"]["status"])
        self.assertEqual("tool_replay_detected", observed["write_second"]["reason"])
        self.assertFalse(observed["write_second"]["executed"])
        self.assertEqual(1, observed["write_executions"])


if __name__ == "__main__":
    unittest.main()

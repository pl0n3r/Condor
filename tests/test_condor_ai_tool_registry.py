#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/Application/AI/AiToolRegistry.php"
VERSION = ROOT / "config/version.php"


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class CondorAiToolRegistryTests(unittest.TestCase):
    def test_registry_resolves_only_exact_registered_descriptor_and_handler(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref', 'limit'],
        'required_inputs' => [],
    ],
);
$executions = 0;
$handler = static function () use (&$executions): array {
    ++$executions;
    return ['status' => 'ok'];
};
$registry = AiToolRegistry::fromArray(
    $policy,
    [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => $handler,
    ]],
);
$resolved = $registry->resolve('catalog.read');
$result = ($resolved['handler'])();

print json_encode([
    'same_descriptor' => $resolved['descriptor'] === $descriptor,
    'result' => $result,
    'executions' => $executions,
], JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["same_descriptor"])
        self.assertEqual({"status": "ok"}, observed["result"])
        self.assertEqual(1, observed["executions"])
        self.assertIn("'version' => '0.1.153'", VERSION.read_text(encoding="utf-8"))

        source = SOURCE.read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo",
            "doctrine",
            "httpclient",
            "curl_",
            "file_get_contents(",
            "fetch(",
        ):
            self.assertNotIn(forbidden, source)

    def test_duplicate_unknown_or_descriptor_mismatch_fails_closed_without_execution(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;

$policy = new AiToolPolicy();
$descriptor = AiToolDescriptor::fromArray(
    $policy,
    [
        'tool' => 'catalog.read',
        'risk' => AiToolPolicy::READ_ONLY,
        'input_names' => ['category_ref'],
        'required_inputs' => [],
    ],
);
$executions = 0;
$handler = static function () use (&$executions): void {
    ++$executions;
};
$registration = [
    'tool' => 'catalog.read',
    'descriptor' => $descriptor,
    'handler' => $handler,
];

$cases = [
    'duplicate' => [$registration, $registration],
    'descriptor_mismatch' => [[
        'tool' => 'inventory.read',
        'descriptor' => $descriptor,
        'handler' => $handler,
    ]],
    'non_closure_handler' => [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => 'strlen',
    ]],
    'extra_shape' => [[
        'tool' => 'catalog.read',
        'descriptor' => $descriptor,
        'handler' => $handler,
        'extra' => true,
    ]],
];

$out = [];
foreach ($cases as $name => $registrations) {
    try {
        AiToolRegistry::fromArray($policy, $registrations);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

$registry = AiToolRegistry::fromArray($policy, [$registration]);
foreach (
    [
        'unknown' => 'inventory.read',
        'noncanonical' => 'Catalog.Read',
        'not_allowlisted' => 'unknown.read',
    ] as $name => $tool
) {
    try {
        $registry->resolve($tool);
        $out[$name] = false;
    } catch (DomainException) {
        $out[$name] = true;
    }
}

$out['executions'] = $executions;
print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(0, observed.pop("executions"))
        self.assertTrue(all(observed.values()), observed)


if __name__ == "__main__":
    unittest.main()

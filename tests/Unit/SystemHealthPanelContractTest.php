<?php

declare(strict_types=1);

/**
 * Run directly: php tests/Unit/SystemHealthPanelContractTest.php
 *
 * Exercises the actual TypeScript parser/state policy via the project's
 * installed esbuild transformer; does not require a network or browser.
 */
$root = dirname(__DIR__, 2);
$component = $root.'/frontend/admin/SystemHealthPanel.tsx';
$source = file_get_contents($component);
if (!is_string($source)) {
    fwrite(STDERR, "Component source not found.\n");
    exit(1);
}

function requireContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    // Static boundary checks complement the compiled functional scenarios.
    foreach ([
        "fetch('/health'",
        "method: 'GET'",
        "credentials: 'same-origin'",
        "cache: 'no-store'",
        'new AbortController()',
        'controller.abort()',
        'globalThis.setTimeout(',
        'globalThis.clearTimeout(',
        'Refrescar',
        'Última lectura',
        'role="alert"',
    ] as $required) {
        requireContract(str_contains($source, $required), 'Missing contract: '.$required);
    }
    foreach (['localStorage', 'sessionStorage', "method: 'POST'", 'Authorization:'] as $forbidden) {
        requireContract(!str_contains($source, $forbidden), 'Forbidden operation: '.$forbidden);
    }

    $sha = '0123456789abcdef0123456789abcdef01234567';
    $fixtures = [
        ['response' => ['status' => 'ok', 'version' => '0.1.195', 'release_sha' => $sha, 'schema_up_to_date' => true], 'wasReady' => false],
        ['response' => ['status' => 'ok', 'version' => '0.1.195', 'release_sha' => $sha, 'schema_up_to_date' => false], 'wasReady' => true],
        ['response' => ['status' => 'error', 'version' => '0.1.195', 'release_sha' => $sha, 'schema_up_to_date' => true], 'wasReady' => false],
        ['response' => ['status' => 'ok', 'version' => '0.1.195', 'release_sha' => $sha], 'wasReady' => false],
        ['response' => ['status' => 'ok', 'version' => '0.1.195', 'release_sha' => 'UNKNOWN', 'schema_up_to_date' => true], 'wasReady' => false],
        ['response' => ['status' => 'ok', 'version' => '<unsafe>', 'release_sha' => $sha, 'schema_up_to_date' => true], 'wasReady' => false],
        ['response' => null, 'wasReady' => false],
        ['response' => null, 'wasReady' => true],
    ];
    $node = <<<'NODE'
const fs = require('node:fs');
const esbuild = require('esbuild');
const source = fs.readFileSync('frontend/admin/SystemHealthPanel.tsx', 'utf8');
const compiled = esbuild.transformSync(source, { loader: 'tsx', format: 'cjs', target: 'es2022' });
const mod = { exports: {} };
new Function('require', 'module', 'exports', compiled.code)(require, mod, mod.exports);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const results = input.map((row) => {
  const parsed = mod.exports.parseHealthPayload(row.response);
  return {
    parsed,
    status: mod.exports.healthStateOf(parsed),
    onFailure: mod.exports.failedHealthState(row.wasReady),
  };
});
process.stdout.write(JSON.stringify(results));
NODE;
    $process = proc_open(
        ['node', '-e', $node],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    requireContract(is_resource($process), 'Could not launch Node contract harness.');
    fwrite($pipes[0], json_encode($fixtures, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    requireContract(proc_close($process) === 0, 'Node contract harness failed: '.$error);

    $results = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
    requireContract(is_array($results) && count($results) === count($fixtures), 'Missing test results.');
    requireContract($results[0]['status'] === 'HEALTHY', 'Valid healthy payload not accepted.');
    requireContract($results[0]['parsed']['release_sha'] === $sha, 'Commit SHA was changed.');
    requireContract($results[1]['status'] === 'ALERT', 'Outdated schema shown as healthy.');
    foreach ([2, 3, 4, 5, 6, 7] as $i) {
        requireContract($results[$i]['status'] === 'UNKNOWN', 'Malformed payload accepted: '.$i);
        requireContract($results[$i]['parsed'] === null, 'Malformed payload persisted: '.$i);
    }
    requireContract($results[6]['onFailure'] === 'UNKNOWN', 'Network failure became healthy.');
    requireContract($results[7]['onFailure'] === 'STALE', 'Timeout after good data did not become STALE.');
    echo "SystemHealthPanel contract: 8 fixtures and read-only/timeout controls PASS.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'SystemHealthPanel contract FAIL: '.$error->getMessage()."\n");
    exit(1);
}

<?php

declare(strict_types=1);

const CLASSIFICATIONS = ['additive', 'destructive', 'unknown'];
const REQUIRED_TOP_LEVEL_KEYS = [
    'classification',
    'dry_run_valid',
    'allowlist_complete',
    'backup_receipt_verified',
    'post_checks',
];
const REQUIRED_POST_CHECKS = ['health', 'schema', 'smoke'];

function booleanFlag(array $payload, string $key): bool
{
    return array_key_exists($key, $payload) && $payload[$key] === true;
}

/**
 * @param mixed $input
 * @return array<string,mixed>
 */
function evaluatePolicy(mixed $input): array
{
    $payload = is_array($input) ? $input : [];
    $exactShape = array_keys($payload) === REQUIRED_TOP_LEVEL_KEYS;

    $requested = $payload['classification'] ?? null;
    $classification = is_string($requested) && in_array($requested, CLASSIFICATIONS, true)
        ? $requested
        : 'unknown';

    $postChecks = isset($payload['post_checks']) && is_array($payload['post_checks'])
        ? $payload['post_checks']
        : [];

    $postShape = array_keys($postChecks) === REQUIRED_POST_CHECKS;
    $gates = [
        'dry_run_valid' => booleanFlag($payload, 'dry_run_valid'),
        'allowlist_complete' => booleanFlag($payload, 'allowlist_complete'),
        'backup_receipt_verified' => booleanFlag($payload, 'backup_receipt_verified'),
        'post_checks' => [
            'health' => booleanFlag($postChecks, 'health'),
            'schema' => booleanFlag($postChecks, 'schema'),
            'smoke' => booleanFlag($postChecks, 'smoke'),
        ],
    ];

    $complete = $exactShape
        && $postShape
        && $gates['dry_run_valid']
        && $gates['allowlist_complete']
        && $gates['backup_receipt_verified']
        && $gates['post_checks']['health']
        && $gates['post_checks']['schema']
        && $gates['post_checks']['smoke'];

    $eligible = $classification === 'additive' && $complete;

    if ($classification !== 'additive') {
        $reason = $classification === 'destructive'
            ? 'destructive_blocked'
            : 'unknown_blocked';
    } elseif (! $complete) {
        $reason = 'evidence_incomplete';
    } else {
        $reason = 'eligible_after_owner_gate';
    }

    return [
        'schema_version' => 1,
        'classification' => $classification,
        'eligible_after_owner_gate' => $eligible,
        'auto_execute' => false,
        'authority' => 'owner_gate_required',
        'reason' => $reason,
        'gates' => $gates,
    ];
}

function main(): int
{
    $raw = stream_get_contents(STDIN);
    if ($raw === false || strlen($raw) > 262144) {
        fwrite(STDERR, "live_schema_policy_failed\n");
        return 2;
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $result = evaluatePolicy($decoded);
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fwrite(STDERR, "live_schema_policy_failed\n");
        return 2;
    }

    fwrite(STDOUT, $encoded.PHP_EOL);

    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main());
}

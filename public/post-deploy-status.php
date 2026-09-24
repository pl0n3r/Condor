<?php

declare(strict_types=1);

use App\Shared\Version\AppVersion;

$projectDir = dirname(__DIR__);
$version = 'unknown';
$releaseSha = 'dev';

try {
    /** @var array{version?: string} $config */
    $config = require $projectDir.'/config/version.php';
    if (is_string($config['version'] ?? null)) {
        $version = $config['version'];
    }
} catch (Throwable) {
    // El diagnóstico permanece disponible aun si la identidad no puede leerse.
}

$autoload = $projectDir.'/vendor/autoload.php';
if (is_file($autoload)) {
    try {
        require_once $autoload;
        $identity = new AppVersion($projectDir);
        $version = $identity->human();
        $releaseSha = $identity->releaseSha();
    } catch (Throwable) {
        // No se exponen detalles internos.
    }
}

$postDeploy = [
    'version' => null,
    'phase' => 'missing',
    'result' => 'unknown',
    'code' => null,
    'reason' => 'unknown',
    'subcode' => null,
    'backup_client' => 'unknown',
    'updated_at' => null,
];

$statusPath = $projectDir.'/var/runtime/post-deploy-status.json';
if (is_file($statusPath) && is_readable($statusPath)) {
    try {
        $decoded = json_decode(
            (string) file_get_contents($statusPath),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $allowedPhases = [
            'bootstrap',
            'lock',
            'schema-check',
            'dry-run',
            'backup',
            'migrate',
            'recheck',
            'cache',
            'complete',
        ];
        $allowedResults = ['running', 'success', 'failure', 'skipped'];
        $allowedReasons = [
            'none',
            'database_url_missing',
            'client_missing',
            'configuration',
            'filesystem',
            'unsupported_option',
            'server_privilege',
            'access_denied',
            'connection',
            'timeout',
            'dump_failed_unknown',
            'unknown',
        ];
        $allowedBackupClients = ['unknown', 'mariadb-dump', 'mysqldump', 'pdo'];
        $reason = $decoded['reason'] ?? 'unknown';
        $subcode = $decoded['subcode'] ?? null;
        $backupClient = $decoded['backup_client'] ?? 'unknown';

        if (
            is_array($decoded)
            && is_string($decoded['version'] ?? null)
            && preg_match('/\\A(?:unknown|\\d+\\.\\d+\\.\\d+)\\z/', $decoded['version']) === 1
            && is_string($decoded['phase'] ?? null)
            && in_array($decoded['phase'], $allowedPhases, true)
            && is_string($decoded['result'] ?? null)
            && in_array($decoded['result'], $allowedResults, true)
            && is_int($decoded['code'] ?? null)
            && $decoded['code'] >= 0
            && $decoded['code'] <= 255
            && is_string($reason)
            && in_array($reason, $allowedReasons, true)
            && (
                $subcode === null
                || (is_int($subcode) && $subcode >= 0 && $subcode <= 255)
            )
            && is_string($backupClient)
            && in_array($backupClient, $allowedBackupClients, true)
            && is_string($decoded['updated_at'] ?? null)
            && preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z\\z/', $decoded['updated_at']) === 1
        ) {
            $postDeploy = [
                'version' => $decoded['version'],
                'phase' => $decoded['phase'],
                'result' => $decoded['result'],
                'code' => $decoded['code'],
                'reason' => $reason,
                'subcode' => $subcode,
                'backup_client' => $backupClient,
                'updated_at' => $decoded['updated_at'],
            ];
        }
    } catch (Throwable) {
        // Archivo ausente/corrupto se informa como estado desconocido.
    }
}

http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

echo json_encode(
    [
        'status' => 'ok',
        'version' => $version,
        'release_sha' => $releaseSha,
        'post_deploy' => $postDeploy,
    ],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);

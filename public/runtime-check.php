<?php

declare(strict_types=1);

use App\Shared\Runtime\RuntimeEnvironment;

$projectDir = dirname(__DIR__);
$version = 'desconocida';

try {
    $config = require $projectDir.'/config/version.php';
    if (is_array($config) && is_string($config['version'] ?? null)) {
        $version = $config['version'];
    }
} catch (Throwable) {
    // La respuesta permanece segura y sin detalles internos.
}

$autoloadFile = $projectDir.'/vendor/autoload.php';
$autoloadPresent = is_file($autoloadFile);
$phpCompatible = PHP_VERSION_ID >= 80500;
$runtimeStorageAvailable = false;

if ($autoloadPresent) {
    try {
        require_once $autoloadFile;
        $runtimeStorageAvailable =
            RuntimeEnvironment::runtimeStorageAvailable($projectDir);
    } catch (Throwable) {
        $runtimeStorageAvailable = false;
    }
}

$ok = $autoloadPresent && $phpCompatible && $runtimeStorageAvailable;

http_response_code($ok ? 200 : 503);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

echo json_encode(
    [
        'status' => $ok ? 'ok' : 'degraded',
        'version' => $version,
        'php_compatible' => $phpCompatible,
        'autoload_present' => $autoloadPresent,
        'runtime_storage_available' => $runtimeStorageAvailable,
    ],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

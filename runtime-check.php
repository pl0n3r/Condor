<?php

declare(strict_types=1);

$projectDir = __DIR__;
$version = 'desconocida';

try {
    $config = require $projectDir.'/config/version.php';
    if (is_array($config) && is_string($config['version'] ?? null)) {
        $version = $config['version'];
    }
} catch (Throwable) {
    // La respuesta permanece segura y sin detalles internos.
}

$autoloadPresent = is_file($projectDir.'/vendor/autoload.php');
$phpCompatible = PHP_VERSION_ID >= 80300;
$projectRuntime = $projectDir.'/var/runtime';

if (is_dir($projectRuntime)) {
    $projectRuntimeWritable = is_writable($projectRuntime);
} elseif (is_dir($projectDir.'/var')) {
    $projectRuntimeWritable = is_writable($projectDir.'/var');
} else {
    $projectRuntimeWritable = is_writable($projectDir);
}

$tempRuntimeWritable = is_writable(sys_get_temp_dir());
$runtimeStorageAvailable = $projectRuntimeWritable || $tempRuntimeWritable;
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

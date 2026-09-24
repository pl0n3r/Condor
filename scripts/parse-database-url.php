<?php

declare(strict_types=1);

use App\Shared\Runtime\DatabaseDsn;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * Termina el parser con un mensaje sanitizado y un código de error.
 */
function fail(string $message): never
{
    fwrite(STDERR, "parse-database-url.php: {$message}\n");
    exit(1);
}

/**
 * Escapa un valor para un option-file de cliente MySQL/MariaDB.
 */
function optionValue(string $value): string
{
    return '"'.str_replace(
        ['\\', '"'],
        ['\\\\', '\\"'],
        $value,
    ).'"';
}

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    fail('falta DATABASE_URL en el entorno.');
}

try {
    $parsed = DatabaseDsn::parse($url);
} catch (Throwable) {
    fail('DATABASE_URL no es una DSN MySQL/MariaDB válida.');
}
unset($url);

$action = $argv[1] ?? '';
$port = $parsed['port'] ?? 3306;
$host = $parsed['host'] ?? '';
$socket = $parsed['unix_socket'] ?? '';

if ($action === 'client-config') {
    $path = $argv[2] ?? '';
    if ($path === '') {
        fail('falta ruta destino para client-config.');
    }

    $config = "[client]\n"
        .'user='.optionValue($parsed['user'])."\n"
        .'password='.optionValue($parsed['password'])."\n";
    if (is_string($host) && $host !== '') {
        $config .= 'host='.optionValue($host)."\n";
    }
    $config .= 'port='.$port."\n";
    if (is_string($socket) && $socket !== '') {
        $config .= 'socket='.optionValue($socket)."\n";
    }

    if (file_put_contents($path, $config, LOCK_EX) === false) {
        fail('no fue posible escribir el option-file MySQL.');
    }
    if (!chmod($path, 0600)) {
        @unlink($path);
        fail('no fue posible proteger el option-file MySQL.');
    }

    exit(0);
}

$value = match ($action) {
    'user' => $parsed['user'],
    'password' => $parsed['password'],
    'host' => $host,
    'port' => $port,
    'database' => $parsed['dbname'],
    default => null,
};
if ($value === null) {
    fail('campo solicitado no soportado.');
}

fwrite(STDOUT, is_int($value) ? (string) $value : $value);

<?php

declare(strict_types=1);

/**
 * Termina el parser con un mensaje sanitizado y un código de error.
 */
function fail(string $message): never
{
    fwrite(STDERR, "parse-database-url.php: {$message}\n");
    exit(1);
}

/** @return array{user:string,password:string,host:string,port:int,database:string} */
function parseDatabaseUrl(string $url): array
{
    $schemeEnd = strpos($url, '://');
    if ($schemeEnd === false) {
        fail('DATABASE_URL no incluye esquema.');
    }

    $scheme = strtolower(substr($url, 0, $schemeEnd));
    if (!in_array($scheme, ['mysql', 'mariadb'], true)) {
        fail('solo se admiten esquemas mysql:// o mariadb://.');
    }

    $rest = substr($url, $schemeEnd + 3);
    $cut = strcspn($rest, '?#');
    $rest = substr($rest, 0, $cut);

    $slash = strpos($rest, '/');
    if ($slash === false) {
        fail('DATABASE_URL no incluye nombre de base.');
    }

    $authority = substr($rest, 0, $slash);
    $database = rawurldecode(substr($rest, $slash + 1));
    $at = strrpos($authority, '@');

    if ($at === false) {
        $userInfo = '';
        $hostPort = $authority;
    } else {
        $userInfo = substr($authority, 0, $at);
        $hostPort = substr($authority, $at + 1);
    }

    $colon = strpos($userInfo, ':');
    if ($colon === false) {
        $user = rawurldecode($userInfo);
        $password = '';
    } else {
        $user = rawurldecode(substr($userInfo, 0, $colon));
        $password = rawurldecode(substr($userInfo, $colon + 1));
    }

    $host = '';
    $port = 3306;
    if (str_starts_with($hostPort, '[')) {
        $close = strpos($hostPort, ']');
        if ($close === false) {
            fail('host IPv6 inválido.');
        }

        $host = substr($hostPort, 1, $close - 1);
        $suffix = substr($hostPort, $close + 1);
        if ($suffix !== '') {
            if (!str_starts_with($suffix, ':')) {
                fail('host/puerto inválido.');
            }
            $portText = substr($suffix, 1);
            if ($portText === '' || !ctype_digit($portText)) {
                fail('puerto inválido.');
            }
            $port = (int) $portText;
        }
    } else {
        $lastColon = strrpos($hostPort, ':');
        if ($lastColon !== false) {
            $host = substr($hostPort, 0, $lastColon);
            $portText = substr($hostPort, $lastColon + 1);
            if ($portText === '' || !ctype_digit($portText)) {
                fail('puerto inválido.');
            }
            $port = (int) $portText;
        } else {
            $host = $hostPort;
        }
    }

    $host = rawurldecode($host);

    if ($host === '') {
        fail('host vacío.');
    }
    if ($port < 1 || $port > 65535) {
        fail('puerto fuera de rango.');
    }
    if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
        fail('nombre de base no válido.');
    }

    foreach ([$user, $password, $host, $database] as $value) {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            fail('DATABASE_URL contiene caracteres de control no permitidos.');
        }
    }

    return [
        'user' => $user,
        'password' => $password,
        'host' => $host,
        'port' => $port,
        'database' => $database,
    ];
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

$parsed = parseDatabaseUrl($url);
$action = $argv[1] ?? '';

if ($action === 'client-config') {
    $path = $argv[2] ?? '';
    if ($path === '') {
        fail('falta ruta destino para client-config.');
    }

    $config = "[client]\n"
        .'user='.optionValue($parsed['user'])."\n"
        .'password='.optionValue($parsed['password'])."\n"
        .'host='.optionValue($parsed['host'])."\n"
        .'port='.$parsed['port']."\n";

    if (file_put_contents($path, $config, LOCK_EX) === false) {
        fail('no fue posible escribir el option-file MySQL.');
    }
    if (!chmod($path, 0600)) {
        @unlink($path);
        fail('no fue posible proteger el option-file MySQL.');
    }

    exit(0);
}

if (!in_array($action, ['user', 'password', 'host', 'port', 'database'], true)) {
    fail('campo solicitado no soportado.');
}

$value = $action === 'port'
    ? (string) $parsed['port']
    : $parsed[$action];

fwrite(STDOUT, $value);

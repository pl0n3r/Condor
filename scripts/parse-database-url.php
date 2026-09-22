<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "parse-database-url.php: {$message}\n");
    exit(1);
}

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    fail('falta DATABASE_URL en el entorno.');
}

$field = $argv[1] ?? '';
if (!in_array($field, ['user', 'password', 'host', 'port', 'database'], true)) {
    fail('campo solicitado no soportado.');
}

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
        $port = (int) substr($suffix, 1);
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

if ($host === '') {
    fail('host vacío.');
}
if ($port < 1 || $port > 65535) {
    fail('puerto fuera de rango.');
}
if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    fail('nombre de base no válido.');
}

$values = [
    'user' => $user,
    'password' => $password,
    'host' => rawurldecode($host),
    'port' => (string) $port,
    'database' => $database,
];

$value = $values[$field];
if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
    fail('el valor contiene caracteres de control no permitidos.');
}

fwrite(STDOUT, $value);

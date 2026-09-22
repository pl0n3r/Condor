<?php

declare(strict_types=1);

use App\Infrastructure\Observability\FatalLog;
use App\Shared\Runtime\RuntimeEnvironment;

$projectDir = dirname(__DIR__);

http_response_code(404);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$autoloadFile = $projectDir.'/vendor/autoload.php';
if (!is_file($autoloadFile)) {
    exit;
}

require_once $autoloadFile;
require_once $projectDir.'/config/bootstrap.php';

$secret = getenv('APP_SECRET');
if (!is_string($secret) || $secret === '') {
    exit;
}

$expectedToken = FatalLog::accessToken($secret);
$providedToken = $_GET['token'] ?? '';

if (
    !is_string($providedToken)
    || !hash_equals($expectedToken, $providedToken)
) {
    exit;
}

http_response_code(200);

$limit = isset($_GET['limit']) && is_numeric($_GET['limit'])
    ? max(1, min(200, (int) $_GET['limit']))
    : 50;

echo json_encode(
    [
        'status' => 'ok',
        'entries' => FatalLog::recent($projectDir, $limit),
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);

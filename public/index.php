<?php

declare(strict_types=1);

use App\Infrastructure\Observability\FatalLog;
use App\Infrastructure\Runtime\ContainerRecovery;
use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

$projectDir = dirname(__DIR__);
$lastError = null;

for ($attempt = 1; $attempt <= 2; ++$attempt) {
    try {
        require_once $projectDir.'/config/bootstrap.php';

        $kernel = new Kernel(
            $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod',
            filter_var(
                $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
        );

        $request = Request::createFromGlobals();
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
        $lastError = null;
        break;
    } catch (Throwable $error) {
        $lastError = $error;

        $canRetry = $attempt === 1
            && class_exists(ContainerRecovery::class)
            && ContainerRecovery::looksLikeStaleContainer($error, $projectDir)
            && ContainerRecovery::clearProdCache($projectDir);

        if (!$canRetry) {
            break;
        }
    }
}

if ($lastError !== null) {
    $error = $lastError;
    $reference = substr(
        hash(
            'sha256',
            get_class($error).microtime(true).random_int(0, PHP_INT_MAX)
        ),
        0,
        12
    );

    error_log(sprintf(
        'Condor bootstrap failure [%s] %s en %s:%d',
        $reference,
        get_class($error),
        basename($error->getFile()),
        $error->getLine()
    ));

    if (class_exists(FatalLog::class)) {
        FatalLog::record($projectDir, $reference, $error);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    $safeReference = htmlspecialchars(
        $reference,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    echo '<!doctype html><html lang="es-CO"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>Condor App — servicio temporalmente no disponible</title>';
    echo '<style>';
    echo 'body{margin:0;min-height:100vh;display:grid;place-items:center;';
    echo 'padding:24px;background:#0b0d10;color:#f5f7fb;';
    echo 'font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}';
    echo 'main{max-width:640px;padding:36px;border:1px solid #2c3138;';
    echo 'border-radius:20px;background:#11151a}h1{margin:0 0 16px;';
    echo 'font-size:clamp(2rem,6vw,3.5rem);line-height:1}';
    echo 'p{color:#c8ced8;line-height:1.6}code{color:#fff}';
    echo '</style></head><body><main>';
    echo '<h1>Condor no pudo iniciar.</h1>';
    echo '<p>El servicio encontró un error de configuración. ';
    echo 'No se expusieron detalles internos.</p>';
    echo '<p>Referencia: <code>'.$safeReference.'</code></p>';
    echo '</main></body></html>';
}

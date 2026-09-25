<?php

declare(strict_types=1);

use App\Infrastructure\Observability\SentryEventSanitizer;
use App\Shared\Version\AppVersion;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Errores de producción hacia Sentry (proyecto Condor en pl0n3r.sentry.io).
 *
 * SENTRY_DSN queda vacío por defecto: la integración no envía eventos hasta
 * que el entorno configure explícitamente el DSN tras la puerta legal vigente.
 * El SDK se configura sin PII por defecto, sin cuerpos de request y con un
 * before_send que elimina query strings, cookies, datos del request, cabeceras
 * sensibles, variables locales de stacktrace y user context.
 */
return static function (ContainerConfigurator $container): void {
    if ($container->env() !== 'prod' || !class_exists(SentryBundle::class)) {
        return;
    }

    $projectDir = dirname(__DIR__, 2);
    $appVersion = new AppVersion($projectDir);
    $release = sprintf(
        'condor@%s+%s',
        $appVersion->human(),
        $appVersion->releaseSha(),
    );

    $container->parameters()->set(
        'env(SENTRY_DSN)',
        '',
    );

    $container->extension('sentry', [
        'dsn' => '%env(SENTRY_DSN)%',
        'register_error_listener' => true,
        'register_error_handler' => true,
        'options' => [
            'environment' => 'production',
            'release' => $release,
            'before_send' => SentryEventSanitizer::class,
            'send_default_pii' => false,
            'max_request_body_size' => 'none',
            'traces_sample_rate' => 0.0,
            'ignore_exceptions' => [
                Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
                Symfony\Component\Security\Core\Exception\AccessDeniedException::class,
            ],
        ],
    ]);
};

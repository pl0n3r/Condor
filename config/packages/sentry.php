<?php

declare(strict_types=1);

use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Errores de producción hacia Sentry (proyecto Condor en pl0n3r.sentry.io).
 *
 * El DSN solo permite ENVIAR eventos a ese proyecto (no lee datos), por eso
 * vive como valor por defecto versionado; SENTRY_DSN en el entorno lo
 * reemplaza y SENTRY_DSN="" lo desactiva. Privacidad: sin PII por defecto,
 * sin cuerpos de request ni cookies, igual que el diagnóstico sanitizado.
 */
return static function (ContainerConfigurator $container): void {
    if ($container->env() !== 'prod' || !class_exists(SentryBundle::class)) {
        return;
    }

    /** @var array{version?: string} $version */
    $version = require dirname(__DIR__).'/version.php';

    $container->parameters()->set(
        'env(SENTRY_DSN)',
        'https://f011cbab6e5d3fa8446cba4809159022@o4512139951865856.ingest.us.sentry.io/4512140002918400',
    );

    $container->extension('sentry', [
        'dsn' => '%env(SENTRY_DSN)%',
        'register_error_listener' => true,
        'register_error_handler' => true,
        'options' => [
            'environment' => 'production',
            'release' => 'condor@'.($version['version'] ?? '0.0.0-dev'),
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

<?php

declare(strict_types=1);

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
];

// Sentry solo en prod y solo si el paquete está instalado: un vendor sin
// sentry/sentry-symfony nunca debe tumbar el arranque de la aplicación.
if (class_exists(Sentry\SentryBundle\SentryBundle::class)) {
    $bundles[Sentry\SentryBundle\SentryBundle::class] = ['prod' => true];
}

return $bundles;

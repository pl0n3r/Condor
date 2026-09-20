<?php

declare(strict_types=1);

namespace App\Shared\Runtime;

use RuntimeException;

final class RuntimeEnvironment
{
    private const FALLBACK_DATABASE_URL = 'mysql://condor_unconfigured:condor_unconfigured@127.0.0.1:3306/condor_unconfigured?charset=utf8mb4';

    public static function prepare(string $projectDir): void
    {
        self::defineIfMissing('APP_ENV', 'prod');
        self::defineIfMissing('APP_DEBUG', '0');

        if (self::read('APP_SECRET') === null) {
            self::define('APP_SECRET', self::runtimeSecret($projectDir));
        }

        if (self::read('DATABASE_URL') === null) {
            self::define('DATABASE_URL', self::FALLBACK_DATABASE_URL);
            error_log('Condor bootstrap: DATABASE_URL no está configurada; las rutas que requieren persistencia permanecerán no disponibles.');
        }
    }

    private static function runtimeSecret(string $projectDir): string
    {
        $runtimeDir = rtrim($projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'runtime';
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0770, true) && !is_dir($runtimeDir)) {
            throw new RuntimeException('No fue posible preparar el directorio runtime de Condor.');
        }

        $secretFile = $runtimeDir.DIRECTORY_SEPARATOR.'app_secret';
        $existing = self::readSecretFile($secretFile);
        if ($existing !== null) {
            return $existing;
        }

        $candidate = bin2hex(random_bytes(32));
        $handle = @fopen($secretFile, 'x');
        if ($handle !== false) {
            try {
                if (fwrite($handle, $candidate.PHP_EOL) === false || !fflush($handle)) {
                    throw new RuntimeException('No fue posible persistir el secreto runtime de Condor.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($secretFile, 0600);

            return $candidate;
        }

        $existing = self::readSecretFile($secretFile);
        if ($existing === null) {
            throw new RuntimeException('El secreto runtime de Condor no es válido.');
        }

        return $existing;
    }

    private static function readSecretFile(string $secretFile): ?string
    {
        if (!is_file($secretFile)) {
            return null;
        }

        $contents = @file_get_contents($secretFile);
        $secret = is_string($contents) ? trim($contents) : '';

        return preg_match('/^[0-9a-f]{64}$/', $secret) === 1 ? $secret : null;
    }

    private static function defineIfMissing(string $name, string $value): void
    {
        if (self::read($name) === null) {
            self::define($name, $value);
        }
    }

    private static function read(string $name): ?string
    {
        foreach ([getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function define(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

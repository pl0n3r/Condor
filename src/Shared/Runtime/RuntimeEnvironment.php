<?php

declare(strict_types=1);

namespace App\Shared\Runtime;

use RuntimeException;

final class RuntimeEnvironment
{
    private const FALLBACK_DATABASE_URL =
        'mysql://127.0.0.1:3306/condor_unconfigured'.
        '?charset=utf8mb4';

    public static function prepare(string $projectDir): void
    {
        self::defineIfMissing('APP_ENV', 'prod');
        self::defineIfMissing('APP_DEBUG', '0');

        if (self::read('APP_SECRET') === null) {
            self::define('APP_SECRET', self::persistentSecret($projectDir));
        }

        if (self::read('DATABASE_URL') === null) {
            self::define('DATABASE_URL', self::FALLBACK_DATABASE_URL);
            error_log(
                'Condor bootstrap: DATABASE_URL no está configurada; '.
                'las rutas con persistencia permanecerán no disponibles.'
            );
        }
    }

    private static function persistentSecret(string $projectDir): string
    {
        $runtimeDir =
            rtrim($projectDir, DIRECTORY_SEPARATOR).
            DIRECTORY_SEPARATOR.'var'.
            DIRECTORY_SEPARATOR.'runtime';

        if (!self::ensureDirectory($runtimeDir)) {
            throw new RuntimeException(
                'No fue posible preparar almacenamiento runtime seguro.'
            );
        }

        $secretFile = $runtimeDir.DIRECTORY_SEPARATOR.'app_secret';
        $existing = self::readSecretFile($secretFile);
        if ($existing !== null) {
            return $existing;
        }

        $candidate = bin2hex(random_bytes(32));
        $handle = @fopen($secretFile, 'x');

        if ($handle === false) {
            for ($attempt = 0; $attempt < 25; ++$attempt) {
                usleep(10000);
                $existing = self::readSecretFile($secretFile);
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw new RuntimeException(
                'No fue posible obtener el secreto runtime persistente.'
            );
        }

        try {
            if (
                fwrite($handle, $candidate.PHP_EOL) === false
                || !fflush($handle)
            ) {
                throw new RuntimeException(
                    'No fue posible persistir el secreto runtime.'
                );
            }
        } finally {
            fclose($handle);
        }

        @chmod($secretFile, 0600);

        return $candidate;
    }

    private static function ensureDirectory(string $runtimeDir): bool
    {
        if (is_link($runtimeDir)) {
            return false;
        }

        if (!is_dir($runtimeDir)) {
            if (!@mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
                return false;
            }
        }

        @chmod($runtimeDir, 0700);

        return is_dir($runtimeDir)
            && !is_link($runtimeDir)
            && is_writable($runtimeDir);
    }

    private static function readSecretFile(string $secretFile): ?string
    {
        if (is_link($secretFile) || !is_file($secretFile)) {
            return null;
        }

        $contents = @file_get_contents($secretFile);
        $secret = is_string($contents) ? trim($contents) : '';

        return preg_match('/^[0-9a-f]{64}$/', $secret) === 1
            ? $secret
            : null;
    }

    private static function defineIfMissing(string $name, string $value): void
    {
        if (self::read($name) === null) {
            self::define($name, $value);
        }
    }

    private static function read(string $name): ?string
    {
        $candidates = [
            getenv($name),
            $_SERVER[$name] ?? null,
            $_ENV[$name] ?? null,
        ];

        foreach ($candidates as $value) {
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

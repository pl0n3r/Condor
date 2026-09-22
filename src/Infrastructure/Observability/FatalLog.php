<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use JsonException;
use Throwable;

/**
 * Registro de fallos fatales que no depende del contenedor de Symfony, de
 * Doctrine ni de Twig. Existe para el escenario en el que el kernel no
 * llega a arrancar (contenedor compilado desincronizado, error de
 * configuración): ni ErrorIncidentSubscriber ni /adminpl0n3r/diagnosticos
 * son alcanzables en ese momento porque ambos requieren que Symfony (y en
 * el segundo caso, la base de datos) funcionen. Este archivo debe poder
 * usarse desde public/index.php justo después del autoload, sin el
 * contenedor de servicios.
 *
 * Cada llamada es defensiva: un fallo al escribir el registro nunca debe
 * interrumpir el manejo del error original que la originó.
 */
final class FatalLog
{
    private const RELATIVE_PATH = 'var/log/platform_fatal.log';
    private const MAX_ENTRIES = 200;
    private const TOKEN_CONTEXT = 'platform-fatal-log-v1';

    /**
     * Deriva el token de acceso al visor sin persistir un secreto nuevo:
     * se calcula a partir de APP_SECRET, que RuntimeEnvironment garantiza
     * incluso cuando DATABASE_URL falla.
     */
    public static function accessToken(string $appSecret): string
    {
        return hash_hmac('sha256', self::TOKEN_CONTEXT, $appSecret);
    }

    public static function record(
        string $projectDir,
        string $referenceId,
        Throwable $error,
    ): void {
        try {
            $sanitizer = new ErrorSanitizer($projectDir);
            $trace = $sanitizer->trace($error);
            $origin = $trace[0] ?? ['file' => '(sin archivo)', 'line' => null];

            $entry = [
                'id' => $referenceId,
                'occurred_at' => gmdate('c'),
                'exception' => $error::class,
                'message' => $sanitizer->message($error),
                'file' => (string) ($origin['file'] ?? '(sin archivo)'),
                'line' => $origin['line'] ?? null,
            ];

            self::append($projectDir, $entry);
        } catch (Throwable) {
            // El registro de fallos nunca debe generar un fallo nuevo.
        }
    }

    /**
     * @return list<array{
     *   id: string,
     *   occurred_at: string,
     *   exception: string,
     *   message: string,
     *   file: string,
     *   line: int|null
     * }>
     */
    public static function recent(string $projectDir, int $limit = 50): array
    {
        $path = self::path($projectDir);
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $lines = array_filter(explode(PHP_EOL, trim($contents)));
        $entries = [];

        foreach (array_slice(array_reverse($lines), 0, max(0, $limit)) as $line) {
            $decoded = self::decodeLine($line);
            if ($decoded !== null) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private static function append(string $projectDir, array $entry): void
    {
        $path = self::path($projectDir);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $line = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!is_string($line)) {
            return;
        }

        @file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($path, 0600);
        self::trim($path);
    }

    private static function trim(string $path): void
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return;
        }

        $lines = array_filter(explode(PHP_EOL, trim($contents)));
        if (count($lines) <= self::MAX_ENTRIES) {
            return;
        }

        $kept = array_slice($lines, -self::MAX_ENTRIES);
        @file_put_contents(
            $path,
            implode(PHP_EOL, $kept).PHP_EOL,
            LOCK_EX,
        );
    }

    /**
     * @return array{
     *   id: string,
     *   occurred_at: string,
     *   exception: string,
     *   message: string,
     *   file: string,
     *   line: int|null
     * }|null
     */
    private static function decodeLine(string $line): ?array
    {
        try {
            $decoded = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $id = $decoded['id'] ?? null;
        $occurredAt = $decoded['occurred_at'] ?? null;
        $exception = $decoded['exception'] ?? null;
        $message = $decoded['message'] ?? null;
        $file = $decoded['file'] ?? null;
        $line = $decoded['line'] ?? null;

        if (
            !is_string($id)
            || !is_string($occurredAt)
            || !is_string($exception)
            || !is_string($message)
            || !is_string($file)
            || !(is_int($line) || $line === null)
        ) {
            return null;
        }

        return [
            'id' => $id,
            'occurred_at' => $occurredAt,
            'exception' => $exception,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ];
    }

    private static function path(string $projectDir): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .self::RELATIVE_PATH;
    }
}

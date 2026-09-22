<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use JsonException;

/**
 * Registro append-only de métricas de request (duración, memoria pico,
 * status HTTP), sin dependencias de Doctrine ni de un servicio de
 * métricas externo — compatible con Hostinger shared hosting (sin
 * daemons ni workers persistentes). Mismo patrón que FatalLog: cada
 * escritura es defensiva y nunca debe interrumpir el request real.
 */
final class RequestMetrics
{
    private const RELATIVE_PATH = 'var/log/request_metrics.log';
    private const MAX_ENTRIES = 500;

    public static function record(
        string $projectDir,
        string $route,
        int $statusCode,
        float $durationMs,
        int $peakMemoryBytes,
    ): void {
        try {
            $entry = [
                'occurred_at' => gmdate('c'),
                'route' => $route,
                'status' => $statusCode,
                'duration_ms' => round($durationMs, 1),
                'peak_memory_mb' => round($peakMemoryBytes / 1_048_576, 2),
            ];

            self::append($projectDir, $entry);
        } catch (\Throwable) {
            // El registro de métricas nunca debe generar un fallo nuevo.
        }
    }

    /**
     * @return list<array{
     *   occurred_at: string,
     *   route: string,
     *   status: int,
     *   duration_ms: float,
     *   peak_memory_mb: float
     * }>
     */
    public static function recent(string $projectDir, int $limit = 200): array
    {
        $path = self::path($projectDir);
        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return [];
        }

        try {
            if (!@flock($handle, LOCK_SH)) {
                return [];
            }

            $contents = stream_get_contents($handle);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

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

    /**
     * Resumen agregado (p50/p95 de duración, tasa de error 5xx) sobre las
     * entradas más recientes — suficiente para un panel de diagnóstico sin
     * necesitar una base de métricas externa.
     *
     * @return array{
     *   count: int,
     *   p50_ms: float,
     *   p95_ms: float,
     *   error_rate: float,
     *   avg_memory_mb: float
     * }
     */
    public static function summary(string $projectDir, int $limit = 200): array
    {
        $entries = self::recent($projectDir, $limit);
        $count = count($entries);

        if ($count === 0) {
            return ['count' => 0, 'p50_ms' => 0.0, 'p95_ms' => 0.0, 'error_rate' => 0.0, 'avg_memory_mb' => 0.0];
        }

        $durations = array_map(static fn(array $entry): float => $entry['duration_ms'], $entries);
        sort($durations);

        $errors = 0;
        $memorySum = 0.0;
        foreach ($entries as $entry) {
            if ($entry['status'] >= 500) {
                $errors++;
            }
            $memorySum += $entry['peak_memory_mb'];
        }

        return [
            'count' => $count,
            'p50_ms' => self::percentile($durations, 0.50),
            'p95_ms' => self::percentile($durations, 0.95),
            'error_rate' => round($errors / $count * 100, 1),
            'avg_memory_mb' => round($memorySum / $count, 2),
        ];
    }

    /** @param list<float> $sortedValues */
    private static function percentile(array $sortedValues, float $fraction): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) ceil($fraction * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return round($sortedValues[$index], 1);
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

        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            return;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return;
            }

            if (fseek($handle, 0, SEEK_END) !== 0) {
                return;
            }

            if (fwrite($handle, $line.PHP_EOL) === false) {
                return;
            }

            fflush($handle);
            @chmod($path, 0600);
            self::trimLocked($handle);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function trimLocked($handle): void
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if (!is_string($contents)) {
            return;
        }

        $lines = array_filter(explode(PHP_EOL, trim($contents)));
        if (count($lines) <= self::MAX_ENTRIES) {
            return;
        }

        $kept = array_slice($lines, -self::MAX_ENTRIES);
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            return;
        }

        fwrite($handle, implode(PHP_EOL, $kept).PHP_EOL);
        fflush($handle);
    }

    /**
     * @return array{
     *   occurred_at: string,
     *   route: string,
     *   status: int,
     *   duration_ms: float,
     *   peak_memory_mb: float
     * }|null
     */
    private static function decodeLine(string $line): ?array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $occurredAt = $decoded['occurred_at'] ?? null;
        $route = $decoded['route'] ?? null;
        $status = $decoded['status'] ?? null;
        $durationMs = $decoded['duration_ms'] ?? null;
        $peakMemoryMb = $decoded['peak_memory_mb'] ?? null;

        if (
            !is_string($occurredAt)
            || !is_string($route)
            || !is_int($status)
            || !(is_float($durationMs) || is_int($durationMs))
            || !(is_float($peakMemoryMb) || is_int($peakMemoryMb))
        ) {
            return null;
        }

        return [
            'occurred_at' => $occurredAt,
            'route' => $route,
            'status' => $status,
            'duration_ms' => (float) $durationMs,
            'peak_memory_mb' => (float) $peakMemoryMb,
        ];
    }

    private static function path(string $projectDir): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .self::RELATIVE_PATH;
    }
}

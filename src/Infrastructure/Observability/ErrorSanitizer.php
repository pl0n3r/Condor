<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Throwable;

final readonly class ErrorSanitizer
{
    private const MAX_MESSAGE = 1200;
    private const MAX_FRAMES = 12;

    public function __construct(private string $projectDir)
    {
    }

    public function message(Throwable $error): string
    {
        $rawMessage = $this->validUtf8($error->getMessage());

        if (
            str_starts_with($error::class, 'Doctrine\\DBAL\\')
            || preg_match('/\\bSQLSTATE(?:\\[[^\\]]+\\])?/i', $rawMessage) === 1
        ) {
            return 'Error de base de datos; SQL y parámetros redactados.';
        }

        $message = preg_replace(
            [
                '/\\bBearer\\s+[A-Za-z0-9._~+\\/-]+=*/i',
                '/\\b(password|passwd|pwd|token|secret|authorization|cookie|'.
                'api[_-]?key|database_url|dsn)\\b\\s*[:=]\\s*[^\\s,;]+/i',
                '/\\b(mysql|mariadb):\\/\\/[^@\\s]+@/i',
                '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i',
                '/(https?:\\/\\/[^\\s?]+)\\?[^\\s]*/i',
            ],
            [
                'Bearer [REDACTED]',
                '$1=[REDACTED]',
                '$1://[REDACTED]@',
                '[EMAIL]',
                '$1?[REDACTED]',
            ],
            $rawMessage,
        );

        $message = is_string($message) ? trim($message) : '';
        if ($message === '') {
            $message = '(sin mensaje)';
        }

        return $this->truncateUtf8Bytes($message, self::MAX_MESSAGE);
    }

    /**
     * @return list<array{file: string, line: int|null, call: string}>
     */
    public function trace(Throwable $error): array
    {
        $frames = [[
            'file' => $this->safeFile($error->getFile()),
            'line' => $error->getLine(),
            'call' => '{throw}',
        ]];

        foreach ($error->getTrace() as $frame) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            $class = isset($frame['class']) && is_string($frame['class'])
                ? $frame['class']
                : '';
            $type = isset($frame['type']) && is_string($frame['type'])
                ? $frame['type']
                : '';
            $function = isset($frame['function']) && is_string($frame['function'])
                ? $frame['function']
                : '(unknown)';

            $frames[] = [
                'file' => $this->safeFile(
                    isset($frame['file']) && is_string($frame['file'])
                        ? $frame['file']
                        : ''
                ),
                'line' => isset($frame['line']) && is_int($frame['line'])
                    ? $frame['line']
                    : null,
                'call' => substr($class.$type.$function, 0, 255),
            ];
        }

        return $frames;
    }

    public function fingerprint(
        Throwable $error,
        ?string $routeName,
        array $trace,
    ): string {
        $origin = $trace[0] ?? ['file' => '', 'line' => null];

        return hash(
            'sha256',
            implode('|', [
                $error::class,
                $routeName ?? '(sin-ruta)',
                (string) ($origin['file'] ?? ''),
                (string) ($origin['line'] ?? ''),
            ]),
        );
    }

    private function validUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $encoded = json_encode(
            $value,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (!is_string($encoded)) {
            return '';
        }

        $decoded = json_decode($encoded, true);

        return is_string($decoded) ? $decoded : '';
    }

    private function truncateUtf8Bytes(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $slice = substr($value, 0, $maxBytes);
        while ($slice !== '' && preg_match('//u', $slice) !== 1) {
            $slice = substr($slice, 0, -1);
        }

        return $slice;
    }

    private function safeFile(string $file): string
    {
        if ($file === '') {
            return '(sin archivo)';
        }

        $project = rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($file, $project)) {
            return substr($file, strlen($project));
        }

        return basename($file);
    }
}

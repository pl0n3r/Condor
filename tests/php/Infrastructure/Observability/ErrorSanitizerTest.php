<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\ErrorSanitizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorSanitizerTest extends TestCase
{
    public function testItRedactsSecretsPiiAndQueryStrings(): void
    {
        $sanitizer = new ErrorSanitizer('/srv/condor');
        $error = new RuntimeException(
            'password=supersecreto token:abc123 user@example.com '
            .'mysql://user:pass@db/condor https://example.com/path?secret=1'
        );

        $message = $sanitizer->message($error);

        self::assertStringNotContainsString('supersecreto', $message);
        self::assertStringNotContainsString('abc123', $message);
        self::assertStringNotContainsString('user@example.com', $message);
        self::assertStringNotContainsString('user:pass', $message);
        self::assertStringNotContainsString('secret=1', $message);
        self::assertStringContainsString('[REDACTED]', $message);
        self::assertStringContainsString('[EMAIL]', $message);
    }

    public function testItNeverPublishesSqlOrDatabaseParameters(): void
    {
        $sanitizer = new ErrorSanitizer('/srv/condor');
        $error = new RuntimeException(
            "SQLSTATE[23000]: Integrity constraint violation while executing "
            ."'INSERT INTO condor_user (email, phone, password_hash) VALUES (?, ?, ?)' "
            ."with params [\"secret@example.test\", \"+573001112233\", \"hash-secreto\"]"
        );

        $message = $sanitizer->message($error);

        self::assertSame(
            'Error de base de datos; SQL y parámetros redactados.',
            $message,
        );
        self::assertStringNotContainsString('INSERT', $message);
        self::assertStringNotContainsString('+573001112233', $message);
        self::assertStringNotContainsString('hash-secreto', $message);
    }

    public function testMessageTruncationAlwaysKeepsValidUtf8(): void
    {
        $sanitizer = new ErrorSanitizer('/srv/condor');
        $error = new RuntimeException(str_repeat('á', 700)."\xC3\x28");

        $message = $sanitizer->message($error);

        self::assertLessThanOrEqual(1200, strlen($message));
        self::assertSame(1, preg_match('//u', $message));
    }

    public function testTraceNeverIncludesArgumentsAndNormalizesFiles(): void
    {
        $sanitizer = new ErrorSanitizer(dirname(__DIR__, 3));
        $error = new RuntimeException('fallo');

        $trace = $sanitizer->trace($error);

        self::assertNotEmpty($trace);
        self::assertSame(['file', 'line', 'call'], array_keys($trace[0]));
        self::assertLessThanOrEqual(12, count($trace));
        foreach ($trace as $frame) {
            self::assertStringNotContainsString(dirname(__DIR__, 3).'/', $frame['file']);
        }
    }
}

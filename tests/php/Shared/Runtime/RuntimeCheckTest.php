<?php

declare(strict_types=1);

namespace App\Tests\Shared\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeCheckTest extends TestCase
{
    public function testDiagnosticIsSafeAndHealthyInCi(): void
    {
        $script = dirname(__DIR__, 4).'/public/runtime-check.php';
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);

        $lines = [];
        $exitCode = 1;
        exec($command, $lines, $exitCode);

        self::assertSame(0, $exitCode);

        $payload = json_decode(
            implode(PHP_EOL, $lines),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('ok', $payload['status'] ?? null);
        self::assertSame('0.1.3', $payload['version'] ?? null);
        self::assertTrue($payload['php_compatible'] ?? false);
        self::assertTrue($payload['autoload_present'] ?? false);
        self::assertTrue($payload['runtime_storage_available'] ?? false);

        self::assertSame(
            [
                'status',
                'version',
                'php_compatible',
                'autoload_present',
                'runtime_storage_available',
            ],
            array_keys($payload)
        );
    }
}

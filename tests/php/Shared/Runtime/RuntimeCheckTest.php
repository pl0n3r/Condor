<?php

declare(strict_types=1);

namespace App\Tests\Shared\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeCheckTest extends TestCase
{
    public function testDiagnosticIsSafeAndHealthyInCi(): void
    {
        ob_start();
        require dirname(__DIR__, 4).'/runtime-check.php';
        $output = ob_get_clean();

        self::assertIsString($output);

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status'] ?? null);
        self::assertSame('0.1.2', $payload['version'] ?? null);
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

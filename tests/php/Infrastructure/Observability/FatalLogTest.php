<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\FatalLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FatalLogTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/condor-fatal-log-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $logFile = $this->projectDir.'/var/log/platform_fatal.log';
        if (is_file($logFile)) {
            unlink($logFile);
        }
        @rmdir($this->projectDir.'/var/log');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testRecordsAndReadsBackAnEntry(): void
    {
        try {
            throw new RuntimeException('mensaje de prueba');
        } catch (RuntimeException $error) {
            FatalLog::record($this->projectDir, 'REF001', $error);
        }

        $entries = FatalLog::recent($this->projectDir);

        self::assertCount(1, $entries);
        self::assertSame('REF001', $entries[0]['id']);
        self::assertSame(RuntimeException::class, $entries[0]['exception']);
        self::assertSame('mensaje de prueba', $entries[0]['message']);
        self::assertSame(basename(__FILE__), $entries[0]['file']);
        self::assertIsInt($entries[0]['line']);
    }

    public function testSanitizesSecretsBeforePersisting(): void
    {
        try {
            throw new RuntimeException(
                'fallo con DATABASE_URL=mysql://user:secreto@host/db y token=abc123',
            );
        } catch (RuntimeException $error) {
            FatalLog::record($this->projectDir, 'REF002', $error);
        }

        $entries = FatalLog::recent($this->projectDir);

        self::assertStringNotContainsString('secreto', $entries[0]['message']);
        self::assertStringNotContainsString('abc123', $entries[0]['message']);
    }

    public function testReturnsMostRecentEntriesFirstAndRespectsLimit(): void
    {
        foreach (['A', 'B', 'C'] as $reference) {
            try {
                throw new RuntimeException('entrada '.$reference);
            } catch (RuntimeException $error) {
                FatalLog::record($this->projectDir, $reference, $error);
            }
        }

        $entries = FatalLog::recent($this->projectDir, 2);

        self::assertCount(2, $entries);
        self::assertSame('C', $entries[0]['id']);
        self::assertSame('B', $entries[1]['id']);
    }

    public function testNeverThrowsWhenTheLogDirectoryCannotBeCreated(): void
    {
        $unwritableParent = $this->projectDir.'/var';
        mkdir($unwritableParent, 0500, true);

        try {
            throw new RuntimeException('sin permisos');
        } catch (RuntimeException $error) {
            FatalLog::record($this->projectDir, 'REF003', $error);
        }

        self::assertSame([], FatalLog::recent($this->projectDir));

        chmod($unwritableParent, 0700);
    }

    public function testAccessTokenIsStableForTheSameSecretAndDiffersForOthers(): void
    {
        $tokenA = FatalLog::accessToken('secreto-uno');
        $tokenB = FatalLog::accessToken('secreto-uno');
        $tokenC = FatalLog::accessToken('secreto-dos');

        self::assertSame($tokenA, $tokenB);
        self::assertNotSame($tokenA, $tokenC);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tokenA);
    }

    public function testRecentReturnsEmptyArrayWhenLogFileIsMissing(): void
    {
        self::assertSame([], FatalLog::recent($this->projectDir));
    }
}

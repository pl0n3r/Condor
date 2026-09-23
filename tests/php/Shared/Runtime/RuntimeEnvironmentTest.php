<?php

declare(strict_types=1);

namespace App\Tests\Shared\Runtime;

use App\Shared\Runtime\RuntimeEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeEnvironmentTest extends TestCase
{
    private string $projectDir;

    /** @var array<string, string|false|null> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            ['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DATABASE_URL'] as $name
        ) {
            $this->previous[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        $this->projectDir =
            sys_get_temp_dir().'/condor-project-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);

        foreach ($this->previous as $name => $value) {
            if (is_string($value)) {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            } else {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }

        parent::tearDown();
    }

    public function testBootstrapUsesUtcTimezone(): void
    {
        $bootstrap = dirname(__DIR__, 4).'/config/bootstrap.php';
        $code = 'require '.var_export($bootstrap, true).';'
            .' echo date_default_timezone_get();';
        $output = [];
        exec(
            escapeshellarg(PHP_BINARY)
            .' -d date.timezone=America/Bogota -r '
            .escapeshellarg($code),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode);
        self::assertSame('UTC', end($output));
    }

    public function testGeneratesAndReusesPersistentRuntimeSecret(): void
    {
        RuntimeEnvironment::prepare($this->projectDir);

        $first = getenv('APP_SECRET');
        self::assertIsString($first);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame(
            $first.PHP_EOL,
            file_get_contents($this->projectDir.'/var/runtime/app_secret')
        );

        $this->clearSecretEnvironment();
        RuntimeEnvironment::prepare($this->projectDir);

        self::assertSame($first, getenv('APP_SECRET'));
    }

    public function testRejectsUnsafeRuntimeStorageInsteadOfUsingSharedTemp(): void
    {
        file_put_contents($this->projectDir.'/var', 'blocked');

        self::assertFalse(
            RuntimeEnvironment::runtimeStorageAvailable($this->projectDir)
        );

        $this->expectException(RuntimeException::class);

        RuntimeEnvironment::prepare($this->projectDir);
    }

    public function testRejectsRuntimeDirectorySymlink(): void
    {
        mkdir($this->projectDir.'/var', 0700, true);
        mkdir($this->projectDir.'/runtime-target', 0700, true);

        if (
            !@symlink(
                $this->projectDir.'/runtime-target',
                $this->projectDir.'/var/runtime'
            )
        ) {
            self::markTestSkipped('El entorno no permite crear symlinks.');
        }

        self::assertFalse(
            RuntimeEnvironment::runtimeStorageAvailable($this->projectDir)
        );

        $this->expectException(RuntimeException::class);

        RuntimeEnvironment::prepare($this->projectDir);
    }

    public function testRejectsSecretFileSymlink(): void
    {
        mkdir($this->projectDir.'/var/runtime', 0700, true);
        file_put_contents(
            $this->projectDir.'/secret-target',
            str_repeat('a', 64).PHP_EOL
        );

        if (
            !@symlink(
                $this->projectDir.'/secret-target',
                $this->projectDir.'/var/runtime/app_secret'
            )
        ) {
            self::markTestSkipped('El entorno no permite crear symlinks.');
        }

        self::assertFalse(
            RuntimeEnvironment::runtimeStorageAvailable($this->projectDir)
        );

        $this->expectException(RuntimeException::class);

        RuntimeEnvironment::prepare($this->projectDir);
    }

    public function testRejectsIncompletePersistentSecret(): void
    {
        mkdir($this->projectDir.'/var/runtime', 0700, true);
        file_put_contents(
            $this->projectDir.'/var/runtime/app_secret',
            'partial'
        );

        self::assertFalse(
            RuntimeEnvironment::runtimeStorageAvailable($this->projectDir)
        );

        $this->expectException(RuntimeException::class);

        RuntimeEnvironment::prepare($this->projectDir);
    }

    public function testProvidesSafeBootstrapDefaultsWithoutSecrets(): void
    {
        RuntimeEnvironment::prepare($this->projectDir);

        self::assertSame('prod', getenv('APP_ENV'));
        self::assertSame('0', getenv('APP_DEBUG'));
        self::assertStringStartsWith(
            'mysql://127.0.0.1:3306/condor_unconfigured',
            (string) getenv('DATABASE_URL')
        );
    }

    public function testRespectsRealEnvironmentConfiguration(): void
    {
        foreach ([
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'APP_SECRET' => str_repeat('a', 64),
            'DATABASE_URL' => 'mysql://db.internal:3306/condor',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        RuntimeEnvironment::prepare($this->projectDir);

        self::assertSame('test', getenv('APP_ENV'));
        self::assertSame('1', getenv('APP_DEBUG'));
        self::assertSame(str_repeat('a', 64), getenv('APP_SECRET'));
        self::assertSame(
            'mysql://db.internal:3306/condor',
            getenv('DATABASE_URL')
        );
        self::assertFileDoesNotExist(
            $this->projectDir.'/var/runtime/app_secret'
        );
    }

    private function clearSecretEnvironment(): void
    {
        putenv('APP_SECRET');
        unset($_ENV['APP_SECRET'], $_SERVER['APP_SECRET']);
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($child) && !is_link($child)
                ? $this->removeTree($child)
                : @unlink($child);
        }

        @rmdir($path);
    }
}

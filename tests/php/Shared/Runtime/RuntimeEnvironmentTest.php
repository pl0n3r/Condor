<?php

declare(strict_types=1);

namespace App\Tests\Shared\Runtime;

use App\Shared\Runtime\RuntimeEnvironment;
use PHPUnit\Framework\TestCase;

final class RuntimeEnvironmentTest extends TestCase
{
    private string $projectDir;
    private string $fallbackDir;

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
        mkdir($this->projectDir, 0770, true);

        $this->fallbackDir =
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).
            DIRECTORY_SEPARATOR.'condor-runtime-'.
            substr(hash('sha256', $this->projectDir), 0, 12);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
        $this->removeTree($this->fallbackDir);

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

    public function testFallsBackWhenProjectRuntimeCannotBeCreated(): void
    {
        file_put_contents($this->projectDir.'/var', 'blocked');

        RuntimeEnvironment::prepare($this->projectDir);

        $first = getenv('APP_SECRET');
        self::assertIsString($first);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertFileExists($this->fallbackDir.'/app_secret');

        $this->clearSecretEnvironment();
        RuntimeEnvironment::prepare($this->projectDir);

        self::assertSame($first, getenv('APP_SECRET'));
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
        if (is_file($path)) {
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
            is_dir($child)
                ? $this->removeTree($child)
                : @unlink($child);
        }

        @rmdir($path);
    }
}

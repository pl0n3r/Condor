<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Runtime;

use App\Infrastructure\Runtime\ContainerRecovery;
use Error;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerRecoveryTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir()
            .'/condor-container-recovery-'.bin2hex(random_bytes(6));
        mkdir(
            $this->projectDir.'/var/cache/prod/ContainerAbc123',
            0700,
            true,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testDetectsErrorsOriginatingInsideTheCompiledContainer(): void
    {
        $containerFile = $this->projectDir
            .'/var/cache/prod/ContainerAbc123/getFooServiceService.php';
        file_put_contents($containerFile, '<?php');

        $error = $this->errorAt($containerFile, 21);

        self::assertTrue(
            ContainerRecovery::looksLikeStaleContainer(
                $error,
                $this->projectDir,
            ),
        );
    }

    public function testIgnoresErrorsOutsideTheCompiledContainerDirectory(): void
    {
        $unrelatedFile = $this->projectDir.'/src/SomeController.php';
        $error = $this->errorAt($unrelatedFile, 10);

        self::assertFalse(
            ContainerRecovery::looksLikeStaleContainer(
                $error,
                $this->projectDir,
            ),
        );
    }

    public function testIgnoresRegularExceptionsEvenInsideTheContainerDirectory(): void
    {
        $containerFile = $this->projectDir
            .'/var/cache/prod/ContainerAbc123/getFooServiceService.php';

        $exception = new RuntimeException('no es un Error fatal');
        $reflection = new \ReflectionProperty(Exception::class, 'file');
        $reflection->setValue($exception, $containerFile);

        self::assertFalse(
            ContainerRecovery::looksLikeStaleContainer(
                $exception,
                $this->projectDir,
            ),
        );
    }

    public function testClearProdCacheRemovesContentsButKeepsTheDirectory(): void
    {
        $result = ContainerRecovery::clearProdCache($this->projectDir);

        self::assertTrue($result);
        self::assertDirectoryExists($this->projectDir.'/var/cache/prod');
        self::assertFileDoesNotExist(
            $this->projectDir.'/var/cache/prod/ContainerAbc123',
        );
    }

    public function testClearProdCacheIsANoOpWhenTheDirectoryDoesNotExist(): void
    {
        $emptyDir = sys_get_temp_dir()
            .'/condor-no-cache-'.bin2hex(random_bytes(6));

        self::assertTrue(ContainerRecovery::clearProdCache($emptyDir));
    }

    public function testClearProdCacheRefusesToRunTwiceConcurrentlyOnTheSameLock(): void
    {
        $lockPath = $this->projectDir.'/var/cache/.recovery.lock';
        $handle = fopen($lockPath, 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $result = ContainerRecovery::clearProdCache($this->projectDir);

        self::assertFalse($result);

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function errorAt(string $file, int $line): Error
    {
        $error = new Error('fallo simulado');
        $fileProperty = new \ReflectionProperty(Error::class, 'file');
        $fileProperty->setValue($error, $file);
        $lineProperty = new \ReflectionProperty(Error::class, 'line');
        $lineProperty->setValue($error, $line);

        return $error;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

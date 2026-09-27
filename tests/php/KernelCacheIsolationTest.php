<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelCacheIsolationTest extends TestCase
{
    public function testEphemeralCacheDoesNotReuseLiveProdContainer(): void
    {
        $previous = getenv('CONDOR_EPHEMERAL_CACHE');
        putenv('CONDOR_EPHEMERAL_CACHE=1');

        try {
            $kernel = new Kernel('prod', false);
            $expectedPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .'condor-symfony-cache-prod-';

            self::assertStringStartsWith($expectedPrefix, $kernel->getCacheDir());
            self::assertStringContainsString(
                '-'.getmypid().'-',
                $kernel->getCacheDir(),
            );
            self::assertStringNotContainsString(
                '/var/cache/prod-v',
                $kernel->getCacheDir(),
            );
        } finally {
            self::restoreEnv('CONDOR_EPHEMERAL_CACHE', $previous);
        }
    }

    public function testDefaultProdCacheIsVersionedByRelease(): void
    {
        $previous = getenv('CONDOR_EPHEMERAL_CACHE');
        putenv('CONDOR_EPHEMERAL_CACHE');

        try {
            $kernel = new Kernel('prod', false);
            /** @var array{version:string} $release */
            $release = require $kernel->getProjectDir().'/config/version.php';

            self::assertSame(
                $kernel->getProjectDir().'/var/cache/prod-v'.$release['version'],
                $kernel->getCacheDir(),
            );
            self::assertNotSame(
                $kernel->getProjectDir().'/var/cache/prod',
                $kernel->getCacheDir(),
            );
        } finally {
            self::restoreEnv('CONDOR_EPHEMERAL_CACHE', $previous);
        }
    }

    private static function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }

        putenv($name.'='.$value);
    }
}

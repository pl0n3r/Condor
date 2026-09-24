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
            self::assertNotSame(
                $kernel->getProjectDir().'/var/cache/prod',
                $kernel->getCacheDir(),
            );
        } finally {
            if ($previous === false) {
                putenv('CONDOR_EPHEMERAL_CACHE');
            } else {
                putenv('CONDOR_EPHEMERAL_CACHE='.$previous);
            }
        }
    }

    public function testDefaultProdCacheRemainsUnchanged(): void
    {
        $previous = getenv('CONDOR_EPHEMERAL_CACHE');
        putenv('CONDOR_EPHEMERAL_CACHE');

        try {
            $kernel = new Kernel('prod', false);
            self::assertSame(
                $kernel->getProjectDir().'/var/cache/prod',
                $kernel->getCacheDir(),
            );
        } finally {
            if ($previous === false) {
                putenv('CONDOR_EPHEMERAL_CACHE');
            } else {
                putenv('CONDOR_EPHEMERAL_CACHE='.$previous);
            }
        }
    }
}

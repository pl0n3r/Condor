<?php

declare(strict_types=1);

namespace App\Tests\Shared\Version;

use App\Shared\Version\AppVersion;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/app-version-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0777, true);
        file_put_contents(
            $this->projectDir.'/config/version.php',
            "<?php\nreturn ['version' => '9.9.9'];\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testReleaseShaPrefersEnvironmentVariable(): void
    {
        putenv('RELEASE_SHA=1111111111111111111111111111111111111111');

        try {
            $version = new AppVersion($this->projectDir);

            self::assertSame(
                '1111111111111111111111111111111111111111',
                $version->releaseSha(),
            );
        } finally {
            putenv('RELEASE_SHA');
        }
    }

    public function testReleaseShaFallsBackToDetachedGitHead(): void
    {
        $sha = str_repeat('a', 40);
        mkdir($this->projectDir.'/.git', 0777, true);
        file_put_contents($this->projectDir.'/.git/HEAD', $sha."\n");

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaFallsBackToSymbolicGitHead(): void
    {
        $sha = str_repeat('b', 40);
        mkdir($this->projectDir.'/.git/refs/heads', 0777, true);
        file_put_contents($this->projectDir.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->projectDir.'/.git/refs/heads/main', $sha."\n");

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaDefaultsToDevWithoutEnvOrGit(): void
    {
        $version = new AppVersion($this->projectDir);

        self::assertSame('dev', $version->releaseSha());
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}

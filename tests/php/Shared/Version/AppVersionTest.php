<?php

declare(strict_types=1);

namespace App\Tests\Shared\Version;

use App\Shared\Version\AppVersion;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    private string $projectDir;
    private string|false $previousReleaseShaProcess;
    private bool $hadReleaseShaServer;
    private mixed $previousReleaseShaServer;
    private bool $hadReleaseShaEnv;
    private mixed $previousReleaseShaEnv;

    protected function setUp(): void
    {
        $this->previousReleaseShaProcess = getenv('RELEASE_SHA');
        $this->hadReleaseShaServer = array_key_exists('RELEASE_SHA', $_SERVER);
        $this->previousReleaseShaServer = $_SERVER['RELEASE_SHA'] ?? null;
        $this->hadReleaseShaEnv = array_key_exists('RELEASE_SHA', $_ENV);
        $this->previousReleaseShaEnv = $_ENV['RELEASE_SHA'] ?? null;

        putenv('RELEASE_SHA');
        unset($_SERVER['RELEASE_SHA'], $_ENV['RELEASE_SHA']);

        $this->projectDir = sys_get_temp_dir().'/app-version-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents(
            $this->projectDir.'/config/version.php',
            "<?php\nreturn ['version' => '9.9.9'];\n",
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreReleaseShaSources();
        } finally {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testReleaseShaPrefersEnvironmentVariable(): void
    {
        putenv('RELEASE_SHA=1111111111111111111111111111111111111111');

        $version = new AppVersion($this->projectDir);

        self::assertSame(
            '1111111111111111111111111111111111111111',
            $version->releaseSha(),
        );
    }

    public function testReleaseShaIgnoresMalformedEnvironmentValue(): void
    {
        $sha = str_repeat('e', 40);
        mkdir($this->projectDir.'/.git', 0700, true);
        file_put_contents($this->projectDir.'/.git/HEAD', $sha."\n");
        putenv('RELEASE_SHA=invalid');

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaFallsBackToDetachedGitHead(): void
    {
        $sha = str_repeat('a', 40);
        mkdir($this->projectDir.'/.git', 0700, true);
        file_put_contents($this->projectDir.'/.git/HEAD', $sha."\n");

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaFallsBackToSymbolicGitHead(): void
    {
        $sha = str_repeat('b', 40);
        mkdir($this->projectDir.'/.git/refs/heads', 0700, true);
        file_put_contents($this->projectDir.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->projectDir.'/.git/refs/heads/main', $sha."\n");

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaFallsBackToPackedGitReference(): void
    {
        $sha = str_repeat('c', 40);
        mkdir($this->projectDir.'/.git', 0700, true);
        file_put_contents($this->projectDir.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->projectDir.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\n"
            .$sha." refs/heads/main\n",
        );

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaResolvesRelativeGitDirWorktree(): void
    {
        $sha = str_repeat('d', 40);
        mkdir($this->projectDir.'/.git-worktree', 0700, true);
        mkdir($this->projectDir.'/.git-common/refs/heads', 0700, true);
        file_put_contents(
            $this->projectDir.'/.git',
            "gitdir: .git-worktree\n",
        );
        file_put_contents(
            $this->projectDir.'/.git-worktree/commondir',
            "../.git-common\n",
        );
        file_put_contents(
            $this->projectDir.'/.git-worktree/HEAD',
            "ref: refs/heads/main\n",
        );
        file_put_contents(
            $this->projectDir.'/.git-common/refs/heads/main',
            $sha."\n",
        );

        $version = new AppVersion($this->projectDir);

        self::assertSame($sha, $version->releaseSha());
    }

    public function testReleaseShaDefaultsToDevWithoutEnvOrGit(): void
    {
        $version = new AppVersion($this->projectDir);

        self::assertSame('dev', $version->releaseSha());
    }

    private function restoreReleaseShaSources(): void
    {
        if ($this->previousReleaseShaProcess === false) {
            putenv('RELEASE_SHA');
        } else {
            putenv('RELEASE_SHA='.$this->previousReleaseShaProcess);
        }

        if ($this->hadReleaseShaServer) {
            $_SERVER['RELEASE_SHA'] = $this->previousReleaseShaServer;
        } else {
            unset($_SERVER['RELEASE_SHA']);
        }

        if ($this->hadReleaseShaEnv) {
            $_ENV['RELEASE_SHA'] = $this->previousReleaseShaEnv;
        } else {
            unset($_ENV['RELEASE_SHA']);
        }
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

            if (is_link($itemPath)) {
                unlink($itemPath);
            } elseif (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}

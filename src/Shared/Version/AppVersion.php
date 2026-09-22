<?php

declare(strict_types=1);

namespace App\Shared\Version;

final readonly class AppVersion
{
    private const SHA_PATTERN = '/\A[0-9a-f]{40}\z/';

    public function __construct(private string $projectDir)
    {
    }

    public function human(): string
    {
        /** @var array{version?: string} $config */
        $config = require $this->projectDir.'/config/version.php';

        return $config['version'] ?? '0.0.0-dev';
    }

    public function releaseSha(): string
    {
        $sha = $_SERVER['RELEASE_SHA'] ?? $_ENV['RELEASE_SHA'] ?? getenv('RELEASE_SHA');

        if (is_string($sha) && $this->isValidSha($sha)) {
            return $sha;
        }

        $fromGit = $this->readShaFromGitHead();

        return $fromGit ?? 'dev';
    }

    /**
     * Lee el SHA de HEAD directamente de Git en disco (sin shell_exec),
     * incluyendo repositorios normales y worktrees con .git tipo gitdir:.
     */
    private function readShaFromGitHead(): ?string
    {
        $gitDir = $this->resolveGitDir();
        if ($gitDir === null) {
            return null;
        }

        $headPath = $gitDir.'/HEAD';
        if (!is_file($headPath) || !is_readable($headPath)) {
            return null;
        }

        $head = trim((string) file_get_contents($headPath));
        if ($this->isValidSha($head)) {
            return $head;
        }

        if (!str_starts_with($head, 'ref: ')) {
            return null;
        }

        $ref = trim(substr($head, 5));
        if ($ref === '') {
            return null;
        }

        $commonDir = $this->resolveCommonGitDir($gitDir);

        foreach (array_unique([$gitDir, $commonDir]) as $refRoot) {
            $sha = $this->readShaFromRefFile($refRoot, $ref);
            if ($sha !== null) {
                return $sha;
            }
        }

        foreach (array_unique([$commonDir, $gitDir]) as $packedRoot) {
            $sha = $this->readShaFromPackedRefs($packedRoot, $ref);
            if ($sha !== null) {
                return $sha;
            }
        }

        return null;
    }

    private function resolveGitDir(): ?string
    {
        $dotGit = $this->projectDir.'/.git';

        if (is_dir($dotGit) && is_readable($dotGit)) {
            return $dotGit;
        }

        if (!is_file($dotGit) || !is_readable($dotGit)) {
            return null;
        }

        $directive = trim((string) file_get_contents($dotGit));
        if (!str_starts_with($directive, 'gitdir: ')) {
            return null;
        }

        return $this->resolveDirectoryPath(
            dirname($dotGit),
            trim(substr($directive, 8)),
        );
    }

    private function resolveCommonGitDir(string $gitDir): string
    {
        $commonDirPath = $gitDir.'/commondir';

        if (!is_file($commonDirPath) || !is_readable($commonDirPath)) {
            return $gitDir;
        }

        $resolved = $this->resolveDirectoryPath(
            $gitDir,
            trim((string) file_get_contents($commonDirPath)),
        );

        return $resolved ?? $gitDir;
    }

    private function resolveDirectoryPath(string $baseDir, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $candidate = str_starts_with($path, '/')
            ? $path
            : $baseDir.'/'.$path;
        $resolved = realpath($candidate);

        return is_string($resolved) && is_dir($resolved) && is_readable($resolved)
            ? $resolved
            : null;
    }

    private function readShaFromRefFile(string $gitDir, string $ref): ?string
    {
        $refPath = $gitDir.'/'.$ref;

        if (!is_file($refPath) || !is_readable($refPath)) {
            return null;
        }

        $sha = trim((string) file_get_contents($refPath));

        return $this->isValidSha($sha) ? $sha : null;
    }

    private function readShaFromPackedRefs(string $gitDir, string $ref): ?string
    {
        $packedRefsPath = $gitDir.'/packed-refs';

        if (!is_file($packedRefsPath) || !is_readable($packedRefsPath)) {
            return null;
        }

        $lines = file(
            $packedRefsPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line), 2);
            if (
                $parts !== false
                && count($parts) === 2
                && $parts[1] === $ref
                && $this->isValidSha($parts[0])
            ) {
                return $parts[0];
            }
        }

        return null;
    }

    private function isValidSha(string $sha): bool
    {
        return preg_match(self::SHA_PATTERN, $sha) === 1;
    }
}

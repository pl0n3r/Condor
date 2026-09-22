<?php

declare(strict_types=1);

namespace App\Shared\Version;

final readonly class AppVersion
{
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

        if (is_string($sha) && $sha !== '') {
            return $sha;
        }

        $fromGit = $this->readShaFromGitHead();

        return $fromGit ?? 'dev';
    }

    /**
     * Lee el SHA de HEAD directamente de .git en disco (sin shell_exec),
     * para desplegar-por-git-pull sin depender de una variable de entorno
     * configurada manualmente en el hosting.
     */
    private function readShaFromGitHead(): ?string
    {
        $gitDir = $this->projectDir.'/.git';
        $headPath = $gitDir.'/HEAD';

        if (!is_file($headPath) || !is_readable($headPath)) {
            return null;
        }

        $head = trim((string) file_get_contents($headPath));

        if (preg_match('/\A[0-9a-f]{40}\z/', $head) === 1) {
            return $head;
        }

        if (!str_starts_with($head, 'ref: ')) {
            return null;
        }

        $ref = substr($head, 5);
        $refPath = $gitDir.'/'.$ref;

        if (is_file($refPath) && is_readable($refPath)) {
            $sha = trim((string) file_get_contents($refPath));

            return preg_match('/\\A[0-9a-f]{40}\\z/', $sha) === 1 ? $sha : null;
        }

        return $this->readShaFromPackedRefs($gitDir, $ref);
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

            $parts = preg_split('/\\s+/', trim($line), 2);
            if (
                $parts !== false
                && count($parts) === 2
                && $parts[1] === $ref
                && preg_match('/\\A[0-9a-f]{40}\\z/', $parts[0]) === 1
            ) {
                return $parts[0];
            }
        }

        return null;
    }
}

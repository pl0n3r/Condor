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

        return is_string($sha) && $sha !== '' ? $sha : 'dev';
    }
}

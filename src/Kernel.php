<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Throwable;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if (getenv('CONDOR_EPHEMERAL_CACHE') === '1') {
            $keyId = trim((string) getenv('CONDOR_CONTROLBOT_KEY_ID'));
            $key = (string) getenv('CONDOR_CONTROLBOT_KEY');
            $allowedIps = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) getenv('CONDOR_CONTROLBOT_ALLOWED_IPS')),
            )));
            $controlBotProfile = (
                $keyId !== ''
                && strlen($key) >= 32
                && $allowedIps !== []
            ) ? 'controlbot-on' : 'controlbot-off';

            return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .'condor-symfony-cache-'
                .$this->getEnvironment()
                .'-'
                .getmypid()
                .'-'
                .$controlBotProfile;
        }

        return $this->getProjectDir()
            .'/var/cache/'
            .$this->getEnvironment()
            .'-v'
            .$this->releaseCacheKey();
    }

    private function releaseCacheKey(): string
    {
        $version = 'unknown';

        try {
            $config = require $this->getProjectDir().'/config/version.php';
            if (
                is_array($config)
                && is_string($config['version'] ?? null)
                && trim($config['version']) !== ''
            ) {
                $version = trim($config['version']);
            }
        } catch (Throwable) {
            // Fail-safe: a stable fallback still avoids an invalid filesystem path.
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);
        if (!is_string($safe)) {
            return 'unknown';
        }

        $safe = trim($safe, '.-_');

        return $safe !== '' ? $safe : 'unknown';
    }
}

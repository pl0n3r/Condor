<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

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

        return parent::getCacheDir();
    }
}

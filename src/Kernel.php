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
            return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .'condor-symfony-cache-'
                .$this->getEnvironment()
                .'-'
                .getmypid();
        }

        return parent::getCacheDir();
    }
}

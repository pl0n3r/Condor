<?php

declare(strict_types=1);

use App\Shared\Runtime\RuntimeEnvironment;
use Symfony\Component\Dotenv\Dotenv;

$projectDir = dirname(__DIR__);

require $projectDir.'/vendor/autoload.php';

$envFile = $projectDir.'/.env';
if (class_exists(Dotenv::class) && is_file($envFile)) {
    (new Dotenv())->usePutenv()->bootEnv($envFile);
}

RuntimeEnvironment::prepare($projectDir);

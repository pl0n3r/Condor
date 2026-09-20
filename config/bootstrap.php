<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$envFile = dirname(__DIR__).'/.env';
if (class_exists(Dotenv::class) && is_file($envFile)) {
    (new Dotenv())->usePutenv()->bootEnv($envFile);
}

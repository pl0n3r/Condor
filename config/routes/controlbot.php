<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $keyId = trim((string) getenv('CONDOR_CONTROLBOT_KEY_ID'));
    $key = (string) getenv('CONDOR_CONTROLBOT_KEY');
    $allowedIps = array_values(array_filter(array_map(
        'trim',
        explode(',', (string) getenv('CONDOR_CONTROLBOT_ALLOWED_IPS')),
    )));

    if ($keyId === '' || strlen($key) < 32 || $allowedIps === []) {
        return;
    }

    $routes->import(
        '../../src/Http/ControlBot/ControlBotStaffController.php',
        'attribute',
    );
};

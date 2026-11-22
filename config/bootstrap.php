<?php

declare(strict_types=1);

use App\Shared\Runtime\RuntimeEnvironment;
use Symfony\Component\Dotenv\Dotenv;

date_default_timezone_set('UTC');

$projectDir = dirname(__DIR__);

require $projectDir.'/vendor/autoload.php';

$envFile = $projectDir.'/.env';
if (class_exists(Dotenv::class) && is_file($envFile)) {
    (new Dotenv())->usePutenv()->bootEnv($envFile);
}

// Activación de módulos persistentes desarrollados: decisión OWNER #1940.
// La variable explícita (incluso 'false') nunca se sobrescribe.
// Roles, tenant y CSRF siguen controlados en cada endpoint.
$moduleFlags = [
    'HR_REAL_DATA_ENABLED',
    'PAYROLL_REAL_DATA_ENABLED',
    'CONTRACTS_REAL_DATA_ENABLED',
    'SOCIAL_SECURITY_REAL_DATA_ENABLED',
    'FINANCE_REAL_DATA_ENABLED',
    'OHS_REAL_DATA_ENABLED',
    'LEGAL_COMPLIANCE_REAL_DATA_ENABLED',
    'GOVERNANCE_REAL_DATA_ENABLED',
    'OPERATIONS_PROCESSES_REAL_DATA_ENABLED',
    'CUSTOMER_SERVICE_REAL_DATA_ENABLED',
    'ASSETS_REAL_DATA_ENABLED',
];

foreach ($moduleFlags as $moduleFlag) {
    if (
        getenv($moduleFlag) !== false
        || array_key_exists($moduleFlag, $_ENV)
        || array_key_exists($moduleFlag, $_SERVER)
    ) {
        continue;
    }

    putenv($moduleFlag.'=true');
    $_ENV[$moduleFlag] = 'true';
    $_SERVER[$moduleFlag] = 'true';
}

RuntimeEnvironment::prepare($projectDir);

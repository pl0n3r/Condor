<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Shared\Version\AppVersion;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(
        AppVersion $version,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        DependencyFactory $migrations,
    ): JsonResponse {
        try {
            $status = $migrations->getMigrationStatusCalculator();
            $schemaUpToDate = 0 === count($status->getNewMigrations())
                && 0 === count($status->getExecutedUnavailableMigrations());
        } catch (Throwable) {
            // Fail closed without leaking DB/schema internals through /health.
            $schemaUpToDate = false;
        }

        return new JsonResponse([
            'status' => 'ok',
            'version' => $version->human(),
            'release_sha' => $version->releaseSha(),
            'schema_up_to_date' => $schemaUpToDate,
        ]);
    }
}

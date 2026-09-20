<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Shared\Version\AppVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(AppVersion $version): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'version' => $version->human(),
            'release_sha' => $version->releaseSha(),
        ]);
    }
}

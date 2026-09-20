<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class ApiContextController extends AbstractController
{
    #[Route('/api/v1/context', name: 'api_context', methods: ['GET'])]
    public function __invoke(
        CurrentTenantForUser $currentTenantForUser,
        AppVersion $version,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        $tenant = $currentTenantForUser->resolve($user);

        return $this->json([
            'tenant' => [
                'id' => $tenant->id(),
                'name' => $tenant->name(),
                'slug' => $tenant->slug(),
            ],
            'version' => $version->human(),
        ]);
    }
}

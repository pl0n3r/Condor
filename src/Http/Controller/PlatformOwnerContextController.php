<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\PlatformOwnerTenantContext;
use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformOwnerContextController extends AbstractController
{
    #[Route(
        '/adminpl0n3r/api/context',
        name: 'api_platform_owner_context',
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        PlatformOwnerTenantContext $context,
        AppVersion $version,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $tenantId = trim((string) $request->query->get('tenant', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = max(
            1,
            min(
                PlatformOwnerTenantContext::MAX_PAGE_SIZE,
                $request->query->getInt(
                    'per_page',
                    PlatformOwnerTenantContext::DEFAULT_PAGE_SIZE,
                ),
            ),
        );
        $tenantPage = $context->tenantPage($page, $perPage);

        return $this->json([
            'mode' => $tenantId === '' ? 'global' : 'tenant',
            'actor' => [
                'id' => $user->id(),
                'name' => $user->displayName(),
                'role' => 'platform_owner',
            ],
            'metrics' => $context->metrics(),
            'tenants' => $tenantPage['items'],
            'tenant_pagination' => [
                'page' => $tenantPage['page'],
                'per_page' => $tenantPage['per_page'],
                'total' => $tenantPage['total'],
                'has_previous' => $tenantPage['has_previous'],
                'has_next' => $tenantPage['has_next'],
            ],
            'selected_tenant' => $tenantId === ''
                ? null
                : $context->tenant($tenantId),
            'version' => $version->human(),
        ]);
    }
}

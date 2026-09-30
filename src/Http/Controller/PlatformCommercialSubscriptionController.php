<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\PlatformCommercialSubscriptionStateManager;
use App\Application\Identity\PlatformOwnerTenantContext;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformCommercialSubscriptionController extends PlatformOwnerApiController
{
    public function __construct(
        private readonly PlatformCommercialSubscriptionStateManager $manager,
        private readonly PlatformOwnerTenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/commercial-subscription/state',
        name: 'platform_commercial_subscription_state',
        methods: ['POST'],
    )]
    public function transition(string $tenantId, Request $request): JsonResponse
    {
        $this->platformOwner();
        $this->requireManagementCsrf(
            $request,
            'platform_commercial_subscription_management',
        );

        // Preserve the canonical tenant 404 before consulting Commercial data.
        $this->tenantContext->tenant($tenantId);

        $payload = $this->jsonPayload($request);
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['target_state'] || !is_string($payload['target_state'])) {
            throw new UnprocessableEntityHttpException(
                'Solo se permite target_state como estado objetivo.',
            );
        }

        try {
            return $this->json(
                $this->manager->transition(
                    $tenantId,
                    $payload['target_state'],
                ),
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }
}

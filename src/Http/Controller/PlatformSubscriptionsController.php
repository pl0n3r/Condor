<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\ManualSubscriptionOperations;
use App\Application\Identity\PlatformOwnerTenantContext;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Platform-owner-only entry point; uses canonical subscriptions and price catalog. */
final class PlatformSubscriptionsController extends PlatformOwnerApiController
{
    public function __construct(
        private readonly PlatformOwnerTenantContext $tenants,
        private readonly ManualSubscriptionOperations $operations,
    ) {
    }

    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/manual-subscription-adjustments',
        name: 'platform_owner_manual_subscription_adjustments',
        methods: ['POST'],
    )]
    public function adjust(string $tenantId, Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, 'platform_commercial_subscription_management');
        $this->tenants->tenant($tenantId);

        try {
            return $this->json(
                $this->operations->adjust($tenantId, $owner->id(), $this->jsonPayload($request)),
                JsonResponse::HTTP_CREATED,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
    }
}

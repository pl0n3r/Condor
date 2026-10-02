<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\PlatformCommercialAdjustmentManager;
use App\Application\Commercial\PlatformCommercialSubscriptionCreator;
use App\Application\Commercial\PlatformCommercialSubscriptionStateManager;
use App\Application\Commercial\PlatformCommercialTrialCreator;
use App\Application\Identity\PlatformOwnerTenantContext;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformCommercialSubscriptionController extends PlatformOwnerApiController
{
    public function __construct(
        private readonly PlatformCommercialSubscriptionCreator $creator,
        private readonly PlatformCommercialAdjustmentManager $adjustments,
        private readonly PlatformCommercialSubscriptionStateManager $manager,
        private readonly PlatformCommercialTrialCreator $trialCreator,
        private readonly PlatformOwnerTenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/commercial-subscription',
        name: 'platform_commercial_subscription_create',
        methods: ['POST'],
    )]
    public function create(string $tenantId, Request $request): JsonResponse
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
        if (
            $keys !== ['plan_version_id']
            || !is_string($payload['plan_version_id'])
            || trim($payload['plan_version_id']) === ''
        ) {
            throw new UnprocessableEntityHttpException(
                'Solo se permite plan_version_id como PlanVersion canónica.',
            );
        }

        try {
            return $this->json(
                $this->creator->create(
                    $tenantId,
                    $payload['plan_version_id'],
                ),
                JsonResponse::HTTP_CREATED,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }


    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/commercial-subscription/adjustments',
        name: 'platform_commercial_subscription_adjustment',
        methods: ['POST'],
    )]
    public function adjust(string $tenantId, Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf(
            $request,
            'platform_commercial_subscription_management',
        );

        $this->tenantContext->tenant($tenantId);

        $payload = $this->jsonPayload($request);
        $keys = array_keys($payload);
        sort($keys);
        if (
            $keys !== [
                'add_on_ids',
                'reason',
                'renews_at',
                'target_plan_version_id',
            ]
            || !is_array($payload['add_on_ids'])
            || !array_is_list($payload['add_on_ids'])
            || !is_string($payload['reason'])
            || (
                $payload['renews_at'] !== null
                && !is_string($payload['renews_at'])
            )
            || !is_string($payload['target_plan_version_id'])
        ) {
            throw new UnprocessableEntityHttpException(
                'Payload de ajuste comercial inválido.',
            );
        }

        try {
            return $this->json(
                $this->adjustments->apply(
                    $tenantId,
                    $payload['target_plan_version_id'],
                    $payload['add_on_ids'],
                    $payload['renews_at'],
                    $payload['reason'],
                    $owner->id(),
                ),
                JsonResponse::HTTP_CREATED,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/commercial-subscription/trial',
        name: 'platform_commercial_trial_create',
        methods: ['POST'],
    )]
    public function startTrial(string $tenantId, Request $request): JsonResponse
    {
        $this->platformOwner();
        $this->requireManagementCsrf(
            $request,
            'platform_commercial_subscription_management',
        );

        // Preserve the canonical tenant 404 before consulting Commercial data.
        $this->tenantContext->tenant($tenantId);

        $payload = $this->jsonPayload($request);
        if ($payload !== []) {
            throw new UnprocessableEntityHttpException(
                'El inicio de trial no acepta plan, duración ni estado.',
            );
        }

        try {
            return $this->json(
                $this->trialCreator->create($tenantId),
                JsonResponse::HTTP_CREATED,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
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

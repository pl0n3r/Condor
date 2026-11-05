<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\PlatformOwnerTenantContext;
use App\Application\Observability\TenantOperationalSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Snapshot operativo de solo lectura: solo propietario, un tenant por petición. */
final class PlatformOperationalSnapshotController extends PlatformOwnerApiController
{
    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/operational-snapshot',
        name: 'platform_owner_operational_snapshot',
        methods: ['GET'],
    )]
    public function __invoke(
        string $tenantId,
        PlatformOwnerTenantContext $tenants,
        TenantOperationalSnapshot $snapshot,
    ): JsonResponse {
        $this->platformOwner();
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/D', $tenantId) !== 1) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }
        $tenants->tenant($tenantId);

        $response = $this->json($snapshot->forTenant($tenantId));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}

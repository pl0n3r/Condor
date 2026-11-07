<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\SocialSecurity\SocialSecuritySyntheticSlicesService;
use App\Domain\Identity\Entity\Membership;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only synthetic projections transported via POST to validate a CSRF-protected
 * fixture body. No real-data path, storage, external API, notifications or PILA operations.
 */
final class SocialSecuritySyntheticSlicesController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialSecuritySyntheticSlicesService $service,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/social-security/synthetic/{slice}/preview',
        name: 'api_social_security_synthetic_preview',
        requirements: ['slice' => 'novelties|contributions|supports|alerts|pila'],
        methods: ['POST'],
    )]
    public function preview(string $branchId, string $slice, Request $request): JsonResponse
    {
        if (!$this->syntheticAllowed()) {
            return $this->locked(['error' => 'feature_unavailable'], Response::HTTP_NOT_FOUND);
        }
        // Synthetic fixtures are never processed when real data is enabled.
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, 'users.view');
        if (!$user->isActive() || !$membership->isActive()
            || $membership->roleKey() !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Solo el propietario puede previsualizar.');
        }
        $company = $branch->legalEntity();
        if ($company === null || $company->tenant()->id() !== $tenant->id()) {
            throw new NotFoundHttpException('Razón social no disponible.');
        }
        if (strlen((string) $request->getContent()) > 32768) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'Fixture demasiado grande.');
        }

        // BranchApiSupport validates the JSON object and top-level allowlist.
        // Decode nested payloads as arrays for strict projection validators.
        $this->payload($request, in_array($slice, ['novelties', 'contributions'], true)
            ? ['company_id', 'policy', 'affiliations', 'novelties']
            : ['company_id', 'reference_date', 'supports', 'alerts']);
        $input = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            throw new UnprocessableEntityHttpException('Fixture inválido.');
        }
        if (($input['company_id'] ?? null) !== $company->id()) {
            // No hint about cross-tenant or other legal-entity IDs.
            throw new NotFoundHttpException('Razón social no disponible.');
        }

        try {
            $result = $this->service->preview($slice, $input, $tenant->id(), $company->id());
        } catch (InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException('Fixture inválido o fuera de alcance.', $exception);
        }
        return $this->locked($result);
    }

    private function syntheticAllowed(): bool
    {
        if (getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true') {
            return false;
        }
        $environment = $this->getParameter('kernel.environment');
        return $environment === 'test'
            || ($environment === 'dev'
                && getenv('CONDOR_SOCIAL_SECURITY_SYNTHETIC_API') === '1'
                && getenv('CONDOR_PRODUCTION_STAGE') === 'construccion');
    }

    /** @param array<string, mixed> $data */
    private function locked(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $reply = $this->json($data, $status);
        $reply->headers->set('Cache-Control', 'private, no-store');
        $reply->headers->set('X-Content-Type-Options', 'nosniff');
        return $reply;
    }
}

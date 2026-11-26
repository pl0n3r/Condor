<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Documents\DocumentApiService;
use App\Application\Documents\DocumentDraftPersistence;
use App\Application\Documents\VerifiedSignature;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * M08 construction-only, synthetic branch HTTP facade. Never enable in prod.
 * settings.* are existing, restricted RBAC permissions for this demo only;
 * dedicated documents.* permissions require a separately claimed catalog change.
 */
final class DocumentsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentDraftPersistence $drafts,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/documents/export', name: 'api_documents_export',
        methods: ['GET'], priority: 10)]
    public function export(string $branchId, Request $request): JsonResponse
    {
        $this->constructionOnly();
        $this->authorizedBranchScope($branchId, 'settings.view');
        throw new HttpException(409, 'Exportación de documentos persistentes pendiente.');
    }

    #[Route('/api/v1/branches/{branchId}/documents/{documentKey}/trace',
        name: 'api_documents_trace', requirements: ['documentKey' => '[a-z][a-z0-9_-]{0,47}'],
        methods: ['GET'])]
    public function trace(string $branchId, string $documentKey, Request $request): JsonResponse
    {
        $this->constructionOnly();
        [$user, , $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $reference = $this->domain(fn (): string => DocumentApiService::referenceFromKey($documentKey));
        $this->findDocument($branch, $user, $reference);
        throw new HttpException(409, 'Traza de documentos persistentes pendiente.');
    }

    #[Route('/api/v1/branches/{branchId}/documents/{documentKey}/evidence',
        name: 'api_documents_evidence', requirements: ['documentKey' => '[a-z][a-z0-9_-]{0,47}'],
        methods: ['POST'])]
    public function attachEvidence(string $branchId, string $documentKey, Request $request): JsonResponse
    {
        $this->constructionOnly();
        $this->requireDocumentWriteCsrf($request);
        [$user, , $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $data = $this->payload($request, ['evidence_ref']);
        if (!is_string($data['evidence_ref'] ?? null)
            || preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $data['evidence_ref']) !== 1) {
            throw new UnprocessableEntityHttpException('Solo se admite evidencia de prueba.');
        }
        $reference = $this->domain(fn (): string => DocumentApiService::referenceFromKey($documentKey));
        $this->findDocument($branch, $user, $reference);
        throw new HttpException(409, 'Evidencia de documentos persistentes pendiente.');
    }

    #[Route('/api/v1/branches/{branchId}/documents', name: 'api_documents_list', methods: ['GET'])]
    public function listing(string $branchId, Request $request): JsonResponse
    {
        $this->constructionOnly();
        [$user, , $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $documents = $this->domain(fn (): array => $this->drafts->listing($branch, $user));
        return $this->json(['documents' => $documents, 'synthetic' => true,
            'can_close' => false, 'signature_gate' => VerifiedSignature::gate()]);
    }

    #[Route('/api/v1/branches/{branchId}/documents/{documentKey}', name: 'api_documents_detail',
        requirements: ['documentKey' => '[a-z][a-z0-9_-]{0,47}'], methods: ['GET'])]
    public function detail(string $branchId, string $documentKey, Request $request): JsonResponse
    {
        $this->constructionOnly();
        [$user, , $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $reference = $this->domain(fn (): string => DocumentApiService::referenceFromKey($documentKey));
        return $this->json(['document' => $this->findDocument($branch, $user, $reference),
            'synthetic' => true, 'can_close' => false]);
    }

    #[Route('/api/v1/branches/{branchId}/documents', name: 'api_documents_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->constructionOnly();
        $this->requireCsrf($request);
        [$user, , $branch] = $this->authorizedBranchScope($branchId, 'settings.create');
        $data = $this->payload($request, ['reference', 'classification']);
        if (!is_string($data['reference'] ?? null) || !is_string($data['classification'] ?? null)) {
            throw new UnprocessableEntityHttpException('Solo se aceptan metadatos sintéticos enumerados.');
        }
        try {
            $document = $this->domain(fn (): array => $this->drafts->create(
                $branch, $user, $data['reference'], $data['classification'],
            ));
        } catch (UniqueConstraintViolationException $exception) {
            throw new HttpException(409, 'Referencia sintética ya persistida.', $exception);
        }
        return $this->json(['document' => $document, 'synthetic' => true], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/documents/{documentKey}/request-review',
        name: 'api_documents_review', requirements: ['documentKey' => '[a-z][a-z0-9_-]{0,47}'], methods: ['POST'])]
    public function requestReview(string $branchId, string $documentKey, Request $request): JsonResponse
    {
        $this->constructionOnly();
        $this->requireDocumentWriteCsrf($request);
        [$user, , $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $this->payload($request, []);
        $reference = $this->domain(fn (): string => DocumentApiService::referenceFromKey($documentKey));
        $this->findDocument($branch, $user, $reference);
        throw new HttpException(409, 'Revisión de documentos persistentes pendiente.');
    }

    /**
     * Shared fail-closed write guard for document evidence and review.
     * Keeps the legacy create path unchanged.
     */
    private function requireDocumentWriteCsrf(Request $request): void
    {
        $this->requireCsrf($request);
    }



    private function constructionOnly(): void
    {
        if (!in_array($this->getParameter('kernel.environment'), ['dev', 'test'], true)) {
            throw new NotFoundHttpException('Módulo documental no disponible.');
        }
    }

    /** @return array<string, mixed> */
    private function findDocument(Branch $branch, User $actor, string $reference): array
    {
        try {
            return $this->drafts->detail($branch, $actor, $reference);
        } catch (\DomainException $exception) {
            throw new NotFoundHttpException('Documento sintético no encontrado.', $exception);
        }
    }
}

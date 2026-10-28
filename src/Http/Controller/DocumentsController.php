<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Documents\DocumentApiService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\Branch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    private const SESSION_KEY = '_condor_m08_synthetic_documents_v1';

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentApiService $documents,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/documents', name: 'api_documents_list', methods: ['GET'])]
    public function listing(string $branchId, Request $request): JsonResponse
    {
        $this->constructionOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $scope = $this->scope($branch);
        $result = $this->documents->listing($this->store($request), $scope);
        $this->audit($tenant, $user, 'documents_synthetic.listed', DocumentApiService::class, $branch->id(), [
            'branch_id' => $branch->id(), 'synthetic' => true,
        ]);
        $this->entityManager->flush();
        return $this->json(['documents' => $result, 'synthetic' => true, 'can_close' => false]);
    }

    #[Route('/api/v1/branches/{branchId}/documents/{documentKey}', name: 'api_documents_detail',
        requirements: ['documentKey' => '[a-z][a-z0-9_-]{0,47}'], methods: ['GET'])]
    public function detail(string $branchId, string $documentKey, Request $request): JsonResponse
    {
        $this->constructionOnly();
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $reference = $this->domain(fn (): string => DocumentApiService::referenceFromKey($documentKey));
        $document = $this->findDocument($request, $this->scope($branch), $reference);
        $this->audit($tenant, $user, 'documents_synthetic.read', DocumentApiService::class, $reference, [
            'branch_id' => $branch->id(), 'synthetic' => true,
        ]);
        $this->entityManager->flush();
        return $this->json(['document' => $document, 'synthetic' => true, 'can_close' => false]);
    }

    #[Route('/api/v1/branches/{branchId}/documents', name: 'api_documents_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->constructionOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.create');
        $data = $this->payload($request, ['reference', 'classification']);
        $reference = $data['reference'] ?? null;
        $classification = $data['classification'] ?? null;
        // Do not coerce numbers, arrays, names, dates, external IDs or uploaded content.
        if (!is_string($reference) || !is_string($classification)) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Solo se aceptan metadatos sintéticos enumerados.');
        }
        $scope = $this->scope($branch);
        $result = $this->domain(fn (): array => $this->documents->create(
            $this->store($request), $scope, $reference, $classification,
        ));
        $this->audit($tenant, $user, 'documents_synthetic.created', DocumentApiService::class, $reference, [
            'branch_id' => $branch->id(), 'synthetic' => true,
        ]);
        $this->entityManager->flush();
        $request->getSession()->set(self::SESSION_KEY, $result['state']);
        return $this->json(['document' => $result['document'], 'synthetic' => true], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/branches/{branchId}/documents/{documentKey}/request-review',
        name: 'api_documents_review', requirements: ['documentKey' => '[a-z][a-z0-9_-]{0,47}'], methods: ['POST'])]
    public function requestReview(string $branchId, string $documentKey, Request $request): JsonResponse
    {
        $this->constructionOnly();
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        // Request body must contain no unverified document values.
        $this->payload($request, []);
        $reference = $this->domain(fn (): string => DocumentApiService::referenceFromKey($documentKey));
        $result = $this->domain(fn (): array => $this->documents->requestReview(
            $this->store($request), $this->scope($branch), $reference,
        ));
        if ($result['changed']) {
            $this->audit($tenant, $user, 'documents_synthetic.review_requested', DocumentApiService::class, $reference, [
                'branch_id' => $branch->id(), 'synthetic' => true,
            ]);
            $this->entityManager->flush();
            $request->getSession()->set(self::SESSION_KEY, $result['state']);
        }
        return $this->json(['document' => $result['document'], 'changed' => $result['changed'],
            'synthetic' => true, 'can_close' => false]);
    }

    private function constructionOnly(): void
    {
        if (!in_array($this->getParameter('kernel.environment'), ['dev', 'test'], true)) {
            throw new NotFoundHttpException('Módulo documental no disponible.');
        }
    }

    private function scope(Branch $branch): string
    {
        $entity = $branch->legalEntity();
        if ($entity === null) {
            throw new NotFoundHttpException('Entidad legal de sede no encontrada.');
        }
        return hash('sha256', $branch->tenant()->id().'|'.$entity->id().'|'.$branch->id());
    }

    /** @return array<string, mixed> */
    private function store(Request $request): array
    {
        $store = $request->getSession()->get(self::SESSION_KEY, []);
        return is_array($store) ? $store : [];
    }

    /** @return array{reference: string, classification: string, status: string} */
    private function findDocument(Request $request, string $scope, string $reference): array
    {
        try {
            return $this->documents->detail($this->store($request), $scope, $reference);
        } catch (\DomainException $exception) {
            throw new NotFoundHttpException('Documento sintético no encontrado.', $exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Compliance\ComplianceAttentionProjection;
use App\Application\Compliance\ComplianceAuditCandidateProjection;
use App\Application\Compliance\ComplianceEvidenceEvaluation;
use App\Application\Compliance\NormativeApplicability;
use App\Application\Compliance\NormativeMatrix;
use App\Application\Compliance\NormativeReviewProjection;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** M06: server-scoped, disposable fixtures; never a legal compliance decision. */
final class LegalComplianceController extends AbstractController
{
    use BranchApiSupport;

    private const DATE = '2026-10-24';

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }
    #[Route(
        '/api/v1/branches/{branchId}/legal-compliance/{section}',
        name: 'api_legal_compliance_preview',
        requirements: ['section' => 'perfil|catalogo|matriz|calendario|evaluaciones|auditorias'],
        methods: ['GET'],
    )]
    public function preview(string $branchId, string $section, Request $request): JsonResponse
    {
        [$actor, $tenant, $branch] = $this->access($request, $branchId, 'settings.view');
        $scope = $this->scope($tenant, $branch);
        $mode = $request->getSession()->get('condor_m06_'.$scope, 'vacio');
        return $this->response([
            'section' => $section,
            'rows' => $mode === 'demo' ? $this->project($section, $scope) : [],
            'synthetic_only' => true,
            'requires_professional_review' => true,
            'automatically_approved' => false,
            'automatically_closed' => false,
        ]);
    }
    #[Route(
        '/api/v1/branches/{branchId}/legal-compliance/fixtures',
        name: 'api_legal_compliance_fixture',
        methods: ['POST', 'PATCH'],
    )]
    public function fixture(string $branchId, Request $request): JsonResponse
    {
        $permission = $request->isMethod('POST') ? 'settings.create' : 'settings.update';
        [$actor, $tenant, $branch] = $this->access($request, $branchId, $permission, true);
        $input = $this->payload($request, ['scenario']);
        if (!in_array($input['scenario'] ?? null, ['demo', 'vacio'], true)) {
            throw new UnprocessableEntityHttpException('Solo se admiten escenarios ficticios.');
        }
        $request->getSession()->set('condor_m06_'.$this->scope($tenant, $branch), $input['scenario']);
        return $this->response(['synthetic_only' => true, 'scenario' => $input['scenario']]);
    }
    /** @return array{0:object,1:Tenant,2:Branch} */
    private function access(Request $request, string $branchId, string $permission, bool $write = false): array
    {
        $environment = $this->getParameter('kernel.environment');
        if (!in_array($environment, ['dev', 'test'], true)
            || getenv('CONDOR_LEGAL_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        ) {
            throw new NotFoundHttpException('M06 ficticio no disponible.');
        }
        if ($write) {
            $this->requireCsrf($request);
        }
        [$actor, $tenant, $branch] = $this->authorizedBranchScope($branchId, $permission);
        return [$actor, $tenant, $branch];
    }
    private function scope(Tenant $tenant, Branch $branch): string
    {
        return hash('sha256', $tenant->id().'|'.$branch->id());
    }
    /** @return list<array<string,mixed>> */
    private function project(string $section, string $scope): array
    {
        $id = 'demo-'.substr($scope, 0, 12);
        $context = [
            'tenant_ref' => $id, 'sector' => 'comercio', 'ciiu' => '4711',
            'municipio' => 'municipio-demo', 'riesgo' => 'ii',
            'tamano_empresa' => 'micro', 'regimen' => 'regimen-demo',
        ];
        $rule = [
            'tenant_ref' => $id, 'kind' => 'OBLIGATORIO', 'approved' => true,
            'reviewer_ref' => 'revisor-ficticio',
            'source' => [
                'kind' => 'official', 'issuer' => 'emisor-ficticio',
                'article' => 'articulo-ficticio', 'version' => 'v1',
                'official_url' => 'https://normas.example.invalid/fixture',
            ],
            'effective_from' => '2026-01-01', 'effective_until' => '2026-12-31',
            'verified_on' => '2026-02-01',
            'conditions' => ['sector' => 'comercio', 'municipio' => 'municipio-demo'],
        ];
        $prohibited = [...$rule, 'kind' => 'PROHIBIDO'];
        $matrix = NormativeMatrix::evaluate([$rule, $prohibited], $context, self::DATE);
        $reviews = NormativeReviewProjection::evaluate([$rule], $context, self::DATE, [[
            'tenant_ref' => $id, 'municipio' => 'municipio-demo',
            'row_index' => 0, 'state' => 'EN_REVISION',
            'evidence_ref' => 'fixture:evidence/demo', 'reviewer_ref' => 'revisor-ficticio',
            'reviewed_on' => '2026-03-10',
        ]]);
        $deadline = [
            'tenant_ref' => $id, 'municipio' => 'municipio-demo',
            'row_index' => 0, 'deadline_ref' => 'fixture:deadline/demo',
            'due_on' => '2026-10-21', 'source_version' => 'v1',
            'verified_on' => '2026-02-01',
        ];
        $attention = ComplianceAttentionProjection::project($reviews, [$deadline], self::DATE);
        $evaluation = ComplianceEvidenceEvaluation::project([
            'tenant_ref' => 'fixture:tenant/'.substr($scope, 0, 12),
            'company_ref' => 'fixture:company/'.substr($scope, 12, 12),
            'reference_date' => self::DATE,
            'obligations' => [[
                'tenant_ref' => 'fixture:tenant/'.substr($scope, 0, 12),
                'company_ref' => 'fixture:company/'.substr($scope, 12, 12),
                'obligation_ref' => 'fixture:obligation/demo',
                'source_ref' => 'fixture:source/demo',
                'effective_from' => '2026-01-01', 'effective_until' => '2026-12-31',
            ]],
            'evidence' => [],
        ]);
        $audits = ComplianceAuditCandidateProjection::project($attention, [[
            'tenant_ref' => $id, 'municipio' => 'municipio-demo',
            'row_index' => 0, 'audit_ref' => 'fixture:audit/demo',
            'action_ref' => 'fixture:action/demo', 'deadline_ref' => 'fixture:deadline/demo',
            'due_on' => '2026-10-21', 'opened_on' => '2026-10-22',
            'reviewed_on' => '2026-10-23',
        ]], self::DATE);
        return match ($section) {
            'perfil' => [[
                'sector' => $context['sector'], 'municipio' => $context['municipio'],
                'evaluacion' => NormativeApplicability::evaluate($rule, $context, self::DATE),
            ]],
            'catalogo' => NormativeMatrix::evaluate([$rule], $context, self::DATE)['rows'],
            'matriz' => $matrix['rows'],
            'calendario' => $attention['rows'],
            'evaluaciones' => $evaluation['rows'],
            'auditorias' => $audits['rows'],
            default => [],
        };
    }
    /** @param array<string,mixed> $data */
    private function response(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}

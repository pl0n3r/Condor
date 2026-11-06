<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance\LegalCompliance;

use App\Application\Compliance\ComplianceApiService;
use App\Application\Compliance\ComplianceAttentionProjection;
use App\Application\Compliance\ComplianceAuditCandidateProjection;
use App\Application\Compliance\ComplianceEvidenceEvaluation;
use App\Application\Compliance\NormativeApplicability;
use App\Application\Compliance\NormativeMatrix;
use App\Application\Compliance\NormativeReviewProjection;
use PHPUnit\Framework\TestCase;

/** Six executable M06 witnesses, restricted to discarded synthetic data. */
final class GestM06VerificationTest extends TestCase
{
    /** @return array<string,string> */
    private static function context(string $tenant = 'tenant-a'): array
    {
        return [
            'tenant_ref' => $tenant, 'sector' => 'comercio', 'ciiu' => '4711',
            'municipio' => 'pereira', 'riesgo' => 'ii',
            'tamano_empresa' => 'micro', 'regimen' => 'regimen-a',
        ];
    }

    /** @return array<string,mixed> */
    private static function rule(string $tenant = 'tenant-a', string $kind = 'OBLIGATORIO'): array
    {
        return [
            'tenant_ref' => $tenant, 'kind' => $kind, 'approved' => true,
            'reviewer_ref' => 'reviewer-synthetic',
            'source' => [
                'kind' => 'official', 'issuer' => 'authority-synthetic',
                'article' => 'article-synthetic', 'version' => 'v1',
                'official_url' => 'https://normas.example.invalid/synthetic/1',
            ],
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31',
            'verified_on' => '2026-02-01',
            'conditions' => ['sector' => 'comercio', 'municipio' => 'pereira'],
        ];
    }

    public function testS01PerfilLegalSinteticoFallaCerrado(): void
    {
        $rule = self::rule();
        $result = NormativeApplicability::evaluate($rule, self::context(), '2026-10-24');
        self::assertSame('APLICA', $result['applicability']);
        self::assertSame('OBLIGATORIO', $result['kind']);

        $foreign = NormativeApplicability::evaluate($rule, self::context('tenant-b'), '2026-10-24');
        self::assertSame('REQUIERE_REVISION', $foreign['kind']);
        self::assertNull($foreign['source']);
        $rule['approved'] = false;
        self::assertSame('REQUIERE_REVISION', NormativeApplicability::evaluate(
            $rule, self::context(), '2026-10-24',
        )['kind']);
        $missing = self::context();
        unset($missing['regimen']);
        self::assertSame('REQUIERE_REVISION', NormativeApplicability::evaluate(
            self::rule(), $missing, '2026-10-24',
        )['kind']);
    }

    public function testS02CatalogoNormativoNoFiltraProcedenciaAjena(): void
    {
        $ours = self::rule();
        $foreign = self::rule('tenant-b', 'PROHIBIDO');
        $foreign['source']['issuer'] = 'foreign-secret';
        $matrix = NormativeMatrix::evaluate([$ours, $foreign], self::context(), '2026-10-24');
        self::assertSame('tenant-a', $matrix['tenant_ref']);
        self::assertCount(1, $matrix['rows']);
        self::assertSame('authority-synthetic', $matrix['rows'][0]['source']['issuer']);
        self::assertStringNotContainsString('foreign-secret', json_encode($matrix, JSON_THROW_ON_ERROR));
        self::assertSame([], NormativeMatrix::evaluate(
            [], self::context(), '2026-10-24',
        )['rows']);
    }

    public function testS03MatrizObligacionesYProhibicionesSinDecisionAutomatica(): void
    {
        $prohibited = self::rule('tenant-a', 'PROHIBIDO');
        $result = NormativeMatrix::evaluate([$prohibited], self::context(), '2026-10-24');
        self::assertSame('PROHIBIDO', $result['rows'][0]['kind']);
        self::assertSame('APLICA', $result['rows'][0]['applicability']);

        $invalid = self::rule();
        $invalid['kind'] = 'CUMPLE';
        $untrusted = NormativeMatrix::evaluate([$invalid], self::context(), '2026-10-24');
        self::assertSame('REQUIERE_REVISION', $untrusted['rows'][0]['kind']);
        self::assertNull($untrusted['rows'][0]['source']);
        $different = self::context();
        $different['municipio'] = 'bogota';
        self::assertSame('NO_APLICA', NormativeMatrix::evaluate(
            [self::rule()], $different, '2026-10-24',
        )['rows'][0]['kind']);
    }

    /** @return array<string,mixed> */
    private static function reviewedRule(): array
    {
        return NormativeReviewProjection::evaluate(
            [self::rule()], self::context(), '2026-10-24',
            [[
                'tenant_ref' => 'tenant-a', 'municipio' => 'pereira',
                'row_index' => 0, 'state' => 'EN_REVISION',
                'evidence_ref' => 'fixture:evidence/document-1',
                'reviewer_ref' => 'reviewer-synthetic', 'reviewed_on' => '2026-03-10',
            ]],
        );
    }

    /** @return array<string,mixed> */
    private static function deadline(): array
    {
        return [
            'tenant_ref' => 'tenant-a', 'municipio' => 'pereira',
            'row_index' => 0, 'deadline_ref' => 'fixture:deadline/notice-1',
            'due_on' => '2026-10-21', 'source_version' => 'v1',
            'verified_on' => '2026-02-01',
        ];
    }

    public function testS04CalendarioSoloSugiereAtencionFicticia(): void
    {
        $review = self::reviewedRule();
        self::assertSame('EN_REVISION', $review['rows'][0]['state']);
        $calendar = ComplianceAttentionProjection::project(
            $review, [self::deadline()], '2026-10-24',
        );
        self::assertSame('EN_REVISION', $calendar['rows'][0]['state']);
        self::assertTrue($calendar['rows'][0]['attention_candidate']);

        $other = self::deadline();
        $other['tenant_ref'] = 'tenant-b';
        $untrusted = ComplianceAttentionProjection::project($review, [$other], '2026-10-24');
        self::assertSame('REQUIERE_REVISION', $untrusted['rows'][0]['state']);
        self::assertFalse($untrusted['rows'][0]['attention_candidate']);
        self::assertNull($untrusted['rows'][0]['deadline_ref']);
    }

    /** @return array<string,mixed> */
    private static function evidencePayload(): array
    {
        return [
            'tenant_ref' => 'fixture:tenant/demo-a',
            'company_ref' => 'fixture:company/demo-a',
            'reference_date' => '2026-10-24',
            'obligations' => [[
                'tenant_ref' => 'fixture:tenant/demo-a',
                'company_ref' => 'fixture:company/demo-a',
                'obligation_ref' => 'fixture:obligation/demo',
                'source_ref' => 'fixture:source/demo',
                'effective_from' => '2026-01-01',
                'effective_until' => '2026-12-31',
            ]],
            'evidence' => [],
        ];
    }

    public function testS06EvaluacionesSinteticasExigenRevisionHumana(): void
    {
        $payload = self::evidencePayload();
        $out = ComplianceEvidenceEvaluation::project($payload);
        self::assertSame('SIN_EVIDENCIA', $out['rows'][0]['state']);
        self::assertSame('SIN_EVIDENCIA_VERIFICADA', $out['pending_evaluations'][0]['reason']);
        self::assertTrue($out['synthetic_only']);
        self::assertFalse($out['automatic_approval']);
        self::assertFalse($out['automatic_closure']);

        $foreign = $payload;
        $foreign['obligations'][0]['tenant_ref'] = 'fixture:tenant/other';
        self::assertSame([], ComplianceEvidenceEvaluation::project($foreign)['rows']);

        $payload['evidence'] = [[
            'tenant_ref' => 'fixture:tenant/demo-a',
            'company_ref' => 'fixture:company/demo-a',
            'obligation_ref' => 'fixture:obligation/demo',
            'evidence_ref' => 'fixture:evidence/demo',
            'issued_on' => '2026-06-01', 'valid_until' => '2026-12-31',
            'review_state' => 'APROBADA', 'reviewed_on' => '2026-06-02',
            'reviewer_ref' => 'fixture:reviewer/synthetic',
        ]];
        $approvedFixture = ComplianceEvidenceEvaluation::project($payload);
        self::assertSame('CUMPLE', $approvedFixture['rows'][0]['state']);
        self::assertTrue($approvedFixture['rows'][0]['requires_professional_review']);
        self::assertFalse($approvedFixture['rows'][0]['automatically_approved']);
        self::assertFalse($approvedFixture['rows'][0]['automatically_closed']);

        $payload['customer_email'] = 'person@example.invalid';
        $this->expectException(\InvalidArgumentException::class);
        ComplianceEvidenceEvaluation::project($payload);
    }

    public function testS07AuditoriasSoloCreanCandidatosSinCierre(): void
    {
        $attention = ComplianceAttentionProjection::project(
            self::reviewedRule(), [self::deadline()], '2026-10-24',
        );
        $audit = [
            'tenant_ref' => 'tenant-a', 'municipio' => 'pereira',
            'row_index' => 0, 'audit_ref' => 'fixture:audit/audit-1',
            'action_ref' => 'fixture:action/remediation-1',
            'deadline_ref' => 'fixture:deadline/notice-1',
            'due_on' => '2026-10-21',
            'opened_on' => '2026-10-22', 'reviewed_on' => '2026-10-23',
        ];
        $candidate = ComplianceAuditCandidateProjection::project(
            $attention, [$audit], '2026-10-24',
        );
        self::assertSame('EN_REVISION', $candidate['rows'][0]['state']);
        self::assertTrue($candidate['rows'][0]['corrective_action_candidate']);

        $audit['tenant_ref'] = 'tenant-b';
        $foreign = ComplianceAuditCandidateProjection::project(
            $attention, [$audit], '2026-10-24',
        );
        self::assertSame('REQUIERE_REVISION', $foreign['rows'][0]['state']);
        self::assertFalse($foreign['rows'][0]['corrective_action_candidate']);
        self::assertNull($foreign['rows'][0]['audit_ref']);
    }

    public function testS06ServicioGenericoRestringeTenantYNoAprueba(): void
    {
        $scope = hash('sha256', 'tenant-a|branch-a');
        $foreign = hash('sha256', 'tenant-b|branch-a');
        $created = ComplianceApiService::createRecord([], $scope, [
            'evidence_ref' => 'fixture:evidence/m06-demo',
            'obligation_ref' => 'fixture:obligation/m06-demo',
            'type' => 'EVALUACION',
        ]);
        $store = $created['store'];
        self::assertSame('PENDIENTE', $created['record']['state']);
        self::assertCount(1, ComplianceApiService::listRecords($store, $scope));
        self::assertSame([], ComplianceApiService::listRecords($store, $foreign));
        self::assertNull(ComplianceApiService::viewRecord(
            $store, $foreign, 'fixture:evidence/m06-demo',
        ));

        try {
            ComplianceApiService::changeState(
                $store, $foreign, 'fixture:evidence/m06-demo', 'EN_REVISION',
            );
            self::fail('Se permitió modificar registros de otro tenant');
        } catch (\DomainException) {
            // Expected cross-tenant rejection from the real service.
        }

        foreach (['APROBADO', 'CUMPLE', 'CERRADO'] as $forbidden) {
            try {
                ComplianceApiService::changeState(
                    $store, $scope, 'fixture:evidence/m06-demo', $forbidden,
                );
                self::fail('Se permitió aprobación/cierre automático');
            } catch (\DomainException) {
                // Review-only state machine; no legal completion.
            }
        }
    }

    public function testApiActualExigeScopePermisosCsrfYBanderaSintetica(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5).'/src/Http/Controller/ComplianceController.php');
        self::assertIsString($source);
        self::assertStringContainsString('/compliance/evidence', $source);
        self::assertStringContainsString("'settings.view'", $source);
        self::assertStringContainsString("'settings.create'", $source);
        self::assertStringContainsString("'settings.update'", $source);
        self::assertStringContainsString('authorizedBranchScope($branchId, $permission)', $source);
        self::assertStringContainsString('requireCsrf($request)', $source);
        self::assertStringContainsString("getenv('CONDOR_COMPLIANCE_SYNTHETIC_API')", $source);
        self::assertStringContainsString("getenv('CONDOR_PRODUCTION_STAGE')", $source);
        // Static contract check only: NOT an authenticated HTTP or browser test.
    }
}

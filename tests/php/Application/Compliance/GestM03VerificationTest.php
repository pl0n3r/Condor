<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance;

use App\Application\Compliance\OccupationalApiService;
use App\Application\Compliance\OccupationalHazardMatrix;
use App\Application\Compliance\OccupationalIncidentInvestigation;
use App\Application\Compliance\OccupationalPpeTraining;
use App\Application\Compliance\OccupationalSafetyPrecheckProjection;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** M03: executable, synthetic-only witnesses; no legal or production attestation. */
final class GestM03VerificationTest extends TestCase
{
    private const TENANT = 'fixture:tenant/demo-a';
    private const ENTITY = 'fixture:legal-entity/demo-a';

    public function testS01PerfilNoAcreditaCumplimiento(): void
    {
        $profile = [
            'fixture_ref' => 'fixture:sgsst-profile/demo',
            'tenant_ref' => self::TENANT,
            'legal_entity_ref' => self::ENTITY,
            'reference_date' => '2026-11-05',
            'risk_category' => 'III',
            'workers_min' => 1,
            'workers_max' => 20,
        ];
        $valid = OccupationalSafetyPrecheckProjection::project(
            $profile, self::TENANT, self::ENTITY, '2026-11-05',
        );
        self::assertSame('EN_REVISION', $valid['state']);
        self::assertTrue($valid['structurally_reviewable']);
        self::assertTrue($valid['requires_professional_review']);
        self::assertTrue($valid['synthetic_only']);

        $foreign = OccupationalSafetyPrecheckProjection::project(
            $profile, 'fixture:tenant/demo-b', self::ENTITY, '2026-11-05',
        );
        self::assertSame('REQUIERE_REVISION', $foreign['state']);
        self::assertFalse($foreign['structurally_reviewable']);
        $profile['email'] = 'persona@example.test';
        self::assertFalse(OccupationalSafetyPrecheckProjection::project(
            $profile, self::TENANT, self::ENTITY, '2026-11-05',
        )['structurally_reviewable']);
    }

    public function testS02S03S04PeligrosControlesEInspeccionesAislados(): void
    {
        $matrix = [
            'fixture_ref' => 'fixture:sgsst-hazard-matrix/demo',
            'tenant_ref' => self::TENANT,
            'legal_entity_ref' => self::ENTITY,
            'hazards' => [[
                'hazard_ref' => 'fixture:sgsst-hazard/demo',
                'tenant_ref' => self::TENANT,
                'legal_entity_ref' => self::ENTITY,
                'probability' => 3,
                'severity' => 4,
                'controls' => [[
                    'control_ref' => 'fixture:sgsst-control/demo',
                    'tenant_ref' => self::TENANT,
                    'legal_entity_ref' => self::ENTITY,
                    'hierarchy' => 'INGENIERIA',
                    'status' => 'PROPUESTO',
                ]],
            ]],
            'inspections' => [[
                'inspection_ref' => 'fixture:sgsst-inspection/demo',
                'tenant_ref' => self::TENANT,
                'legal_entity_ref' => self::ENTITY,
                'hazard_ref' => 'fixture:sgsst-hazard/demo',
                'scheduled_on' => '2026-11-20',
                'status' => 'PROGRAMADA',
            ]],
        ];
        $valid = OccupationalHazardMatrix::project($matrix, self::TENANT, self::ENTITY);
        self::assertSame(1, $valid['hazard_count']);
        self::assertSame(1, $valid['inspection_count']);
        self::assertSame(12, $valid['hazards'][0]['risk_score_synthetic']);
        self::assertSame('INGENIERIA', $valid['hazards'][0]['controls'][0]['hierarchy']);
        self::assertSame('PROGRAMADA', $valid['inspections'][0]['state']);
        self::assertSame('REQUIERE_REVISION', $valid['state']);
        self::assertTrue($valid['synthetic_only']);
        self::assertSame(0, OccupationalHazardMatrix::project(
            $matrix, 'fixture:tenant/demo-b', self::ENTITY,
        )['hazard_count']);
        self::assertSame(0, OccupationalHazardMatrix::project(
            $matrix, self::TENANT, 'fixture:legal-entity/demo-b',
        )['inspection_count']);
        $matrix['hazards'][0]['controls'][0]['status'] = 'APROBADO';
        self::assertSame(0, OccupationalHazardMatrix::project(
            $matrix, self::TENANT, self::ENTITY,
        )['hazard_count']);
    }

    public function testS05S06IncidenteEInvestigacionSinCierreAutomatico(): void
    {
        $fixture = [
            'tenant_ref' => self::TENANT,
            'reference_date' => '2026-11-05',
            'incidents' => [[
                'tenant_ref' => self::TENANT,
                'incident_ref' => 'fixture:incident/demo',
                'kind' => 'NEAR_MISS',
                'occurred_on' => '2026-10-01',
                'reported_on' => '2026-10-02',
                'summary_ref' => 'fixture:summary/demo',
            ]],
            'investigations' => [[
                'tenant_ref' => self::TENANT,
                'incident_ref' => 'fixture:incident/demo',
                'started_on' => '2026-10-03',
                'due_on' => '2026-11-01',
                'deadline_ref' => 'fixture:deadline/demo',
                'actions' => [[
                    'action_ref' => 'fixture:action/demo',
                    'description_ref' => 'fixture:summary/action',
                ]],
            ]],
            'sensitive_fixtures' => [],
        ];
        $valid = OccupationalIncidentInvestigation::project($fixture);
        self::assertCount(1, $valid['incidents']);
        self::assertSame('EN_REVISION', $valid['incidents'][0]['investigation_state']);
        self::assertTrue($valid['incidents'][0]['deadline']['attention_candidate']);
        self::assertSame('FIXTURE_ONLY_NOT_LEGAL', $valid['incidents'][0]['deadline']['basis']);
        self::assertSame('PENDIENTE_REVISION', $valid['incidents'][0]['actions'][0]['state']);
        self::assertFalse($valid['automatic_approval']);
        self::assertFalse($valid['automatic_closure']);
        $fixture['tenant_ref'] = 'fixture:tenant/demo-b';
        self::assertSame([], OccupationalIncidentInvestigation::project($fixture)['incidents']);
        $fixture['incidents'][0]['worker_name'] = 'persona real';
        $this->expectException(InvalidArgumentException::class);
        OccupationalIncidentInvestigation::project($fixture);
    }

    public function testS07S08S09S10ActividadesSinteticasYSinAprobacion(): void
    {
        $kinds = [
            'EPP_ENTREGA', 'EPP_REPOSICION', 'CAPACITACION',
            'SIMULACRO_EMERGENCIA', 'COMITE_VIGIA',
        ];
        $activities = [];
        foreach ($kinds as $i => $kind) {
            $activities[] = [
                'activity_ref' => 'fixture:activity/demo-'.$i,
                'tenant_ref' => self::TENANT,
                'company_ref' => 'fixture:company/demo',
                'kind' => $kind,
                'occurred_on' => '2026-10-01',
                'due_on' => '2026-12-31',
                'evidence_ref' => 'fixture:evidence/demo',
                'attendee_refs' => $kind === 'CAPACITACION'
                    ? ['fixture:participant/demo'] : [],
                'review_state' => 'PENDIENTE',
            ];
        }
        $input = [
            'tenant_ref' => self::TENANT,
            'company_ref' => 'fixture:company/demo',
            'reference_date' => '2026-11-05',
            'activities' => $activities,
        ];
        $valid = OccupationalPpeTraining::project($input);
        self::assertSame($kinds, array_column($valid['rows'], 'kind'));
        self::assertSame(array_fill(0, 5, 'PENDIENTE'), array_column($valid['rows'], 'state'));
        self::assertCount(5, $valid['pending_evaluations']);
        self::assertTrue($valid['synthetic_only']);
        self::assertFalse($valid['automatic_approval']);
        self::assertFalse($valid['automatic_closure']);
        $input['activities'][] = [
            ...$activities[0], 'activity_ref' => 'fixture:activity/foreign',
            'tenant_ref' => 'fixture:tenant/demo-b',
        ];
        self::assertCount(5, OccupationalPpeTraining::project($input)['rows']);
        $input['activities'][0]['email'] = 'real@example.test';
        $this->expectException(InvalidArgumentException::class);
        OccupationalPpeTraining::project($input);
    }

    public function testHttpServicioRestringeTenantYEstados(): void
    {
        $scope = hash('sha256', 'tenant-a|branch-a');
        $other = hash('sha256', 'tenant-b|branch-a');
        $store = [];
        foreach (['PELIGRO', 'INSPECCION', 'INCIDENTE', 'EPP', 'CAPACITACION', 'EMERGENCIA'] as $kind) {
            $ref = 'fixture:sgsst-record/'.strtolower($kind);
            $out = OccupationalApiService::createRecord($store, $scope, [
                'record_ref' => $ref,
                'source_ref' => 'fixture:sgsst-source/demo',
                'kind' => $kind,
            ]);
            $store = $out['store'];
            self::assertSame('PENDIENTE', $out['record']['state']);
            self::assertTrue($out['record']['requires_professional_review']);
            self::assertFalse($out['record']['automatically_approved']);
        }
        self::assertCount(6, OccupationalApiService::listRecords($store, $scope));
        self::assertSame([], OccupationalApiService::listRecords($store, $other));
        self::assertNull(OccupationalApiService::viewRecord(
            $store, $other, 'fixture:sgsst-record/peligro',
        ));
        $this->expectException(DomainException::class);
        OccupationalApiService::changeState(
            $store, $other, 'fixture:sgsst-record/peligro', 'EN_REVISION',
        );
    }

    public function testControladorCierraPermisosBanderaYCrsf(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 4).'/src/Http/Controller/ComplianceController.php',
        );
        self::assertIsString($controller);
        $occupational = explode(
            '// SG-SST intentionally uses a distinct synthetic session store',
            $controller, 2,
        )[1] ?? '';
        self::assertSame(4, substr_count($occupational, '#[Route('));
        self::assertSame(4, substr_count($occupational, 'occupationalAccess($request, $branchId'));
        self::assertContains('settings.view', [$this->readPermission($occupational, 'settings.view')]);
        self::assertStringContainsString('settings.create', $occupational);
        self::assertStringContainsString('settings.update', $occupational);
        self::assertStringContainsString('authorizedBranchScope($branchId, $permission)', $occupational);
        self::assertStringContainsString('requireCsrf($request)', $occupational);
        self::assertStringContainsString("getenv('CONDOR_OCCUPATIONAL_SYNTHETIC_API')", $occupational);
        self::assertStringContainsString("getenv('CONDOR_PRODUCTION_STAGE')", $occupational);
        self::assertStringContainsString("'condor_sgsst_synthetic'", $occupational);
        self::assertSame(4, substr_count($occupational, 'occupationalAudit($tenant, $actor'));
    }

    private function readPermission(string $text, string $permission): string
    {
        return str_contains($text, "'".$permission."'") ? $permission : '';
    }
}

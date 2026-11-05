<?php

declare(strict_types=1);

namespace App\Tests\Application\HumanResources;

use App\Application\HumanResources\HumanResourcesPreview as Preview;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Diez contratos M01 offline; NO certifican persistencia, UI conectada ni PII. */
final class GestM01PreviewTest extends TestCase
{
    private const DATE = '2026-11-05';

    /** @return array<string,mixed> */
    private static function structure(): array
    {
        $empty = Preview::project('structure', 'tenant-a', 'company-a', [
            'as_of' => self::DATE, 'areas' => [], 'sites' => [], 'positions' => [],
        ]);
        $scope = ['tenant_ref' => $empty['tenant_ref'], 'company_ref' => $empty['company_ref']];
        return [
            'as_of' => self::DATE,
            'areas' => [
                $scope + ['ref' => 'fixture:area/root', 'parent_ref' => null],
                $scope + ['ref' => 'fixture:area/child', 'parent_ref' => 'fixture:area/root'],
            ],
            'sites' => [$scope + ['ref' => 'fixture:site/main']],
            'positions' => [$scope + [
                'ref' => 'fixture:position/lead', 'area_ref' => 'fixture:area/root',
                'site_ref' => 'fixture:site/main', 'parent_ref' => null,
                'employee_ref' => null,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function leaves(): array
    {
        $empty = Preview::project('leave', 'tenant-a', 'company-a', [
            'as_of' => self::DATE, 'requests' => [], 'balances' => [],
        ]);
        $scope = ['tenant_ref' => $empty['tenant_ref'], 'company_ref' => $empty['company_ref']];
        $employee = 'fixture:employee/demo';
        $base = $scope + ['employee_ref' => $employee];
        return [
            'as_of' => self::DATE,
            'balances' => [$base + ['days' => 1]],
            'requests' => [
                $base + [
                    'ref' => 'fixture:request/permission', 'kind' => 'PERMISO',
                    'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'state' => 'PENDIENTE',
                ],
                $base + [
                    'ref' => 'fixture:request/vacation', 'kind' => 'VACACIONES',
                    'starts_on' => '2026-11-01', 'ends_on' => '2026-11-04', 'state' => 'EN_REVISION',
                ],
                $base + [
                    'ref' => 'fixture:request/sick', 'kind' => 'INCAPACIDAD',
                    'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04', 'state' => 'PENDIENTE',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function performance(): array
    {
        $empty = Preview::project('performance', 'tenant-a', 'company-a', [
            'as_of' => self::DATE, 'viewer_employee_ref' => 'fixture:employee/demo',
            'evaluations' => [],
        ]);
        $scope = ['tenant_ref' => $empty['tenant_ref'], 'company_ref' => $empty['company_ref']];
        return [
            'as_of' => self::DATE, 'viewer_employee_ref' => 'fixture:employee/demo',
            'evaluations' => [
                $scope + [
                    'ref' => 'fixture:evaluation/own', 'cycle_ref' => 'fixture:cycle/q4',
                    'employee_ref' => 'fixture:employee/demo',
                    'evaluator_ref' => 'fixture:employee/demo', 'kind' => 'AUTOEVALUACION',
                    'state' => 'BORRADOR', 'score' => 82,
                ],
                $scope + [
                    'ref' => 'fixture:evaluation/other', 'cycle_ref' => 'fixture:cycle/q4',
                    'employee_ref' => 'fixture:employee/other',
                    'evaluator_ref' => 'fixture:employee/manager', 'kind' => 'JEFE',
                    'state' => 'EN_REVISION', 'score' => 66,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    private static function project(string $kind, array $input): array
    {
        return Preview::project($kind, 'tenant-a', 'company-a', $input);
    }

    private static function rejects(callable $action): void
    {
        try {
            $action();
            self::fail('La proyección debía rechazar el dato o alcance inválido.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    public function testS02CargosSinResponsableExigenRevision(): void
    {
        $result = self::project('structure', self::structure());
        self::assertTrue($result['valid']);
        self::assertTrue($result['positions'][0]['without_responsible']);
        self::assertSame('REQUIERE_REVISION', $result['positions'][0]['state']);
    }

    public function testS03AreasDetectanCiclos(): void
    {
        $input = self::structure();
        $input['areas'][0]['parent_ref'] = 'fixture:area/child';
        $result = self::project('structure', $input);
        self::assertTrue($result['has_cycles']);
        self::assertFalse($result['can_approve']);
    }

    public function testS04SedesAisladasPorEmpresa(): void
    {
        $input = self::structure();
        $input['sites'][] = [
            'tenant_ref' => 'tenant-b', 'company_ref' => 'fixture:company/foreign',
            'ref' => 'fixture:site/foreign',
        ];
        $result = self::project('structure', $input);
        self::assertCount(1, $result['sites']);
        self::assertSame('fixture:site/main', $result['sites'][0]['ref']);
        self::assertStringNotContainsString('foreign', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testS05OrganigramaRechazaPadreInexistente(): void
    {
        $input = self::structure();
        $input['positions'][0]['parent_ref'] = 'fixture:position/unknown';
        self::rejects(static fn () => self::project('structure', $input));
    }

    public function testS06NovedadesDetectanSolapamiento(): void
    {
        $result = self::project('leave', self::leaves());
        self::assertCount(3, $result['requests']);
        self::assertTrue($result['requests'][0]['overlaps']);
        self::assertFalse($result['automatically_closed']);
    }

    public function testS07PermisosNoAutorizanAprobacionAutomatica(): void
    {
        $result = self::project('leave', self::leaves());
        $permission = array_values(array_filter(
            $result['requests'], static fn (array $row): bool => $row['kind'] === 'PERMISO',
        ));
        self::assertCount(1, $permission);
        self::assertSame('PENDIENTE', $permission[0]['state']);
        self::assertFalse($permission[0]['automatically_approved']);
    }

    public function testS08VacacionesDetectanSaldoInsuficiente(): void
    {
        $rows = self::project('leave', self::leaves())['requests'];
        $vacation = array_values(array_filter(
            $rows, static fn (array $row): bool => $row['kind'] === 'VACACIONES',
        ));
        self::assertCount(1, $vacation);
        self::assertTrue($vacation[0]['insufficient_balance']);
        self::assertSame(4, $vacation[0]['requested_days']);
    }

    public function testS09IncapacidadesNoAdmitenDiagnostico(): void
    {
        $input = self::leaves();
        $result = self::project('leave', $input);
        self::assertCount(1, array_filter(
            $result['requests'], static fn (array $r): bool => $r['kind'] === 'INCAPACIDAD',
        ));
        $input['requests'][2]['diagnosis'] = 'dato-sensible';
        self::rejects(static fn () => self::project('leave', $input));
    }

    public function testS10EvaluacionesSinCalificacionInvalida(): void
    {
        $result = self::project('performance', self::performance());
        self::assertSame(82, $result['evaluations'][0]['score']);
        self::assertTrue($result['evaluations'][0]['needs_human_review']);
        $input = self::performance();
        $input['evaluations'][0]['score'] = 101;
        self::rejects(static fn () => self::project('performance', $input));
    }

    public function testS11PortalRestringeVisorYNoCierra(): void
    {
        $result = self::project('performance', self::performance());
        self::assertCount(1, $result['evaluations']);
        self::assertSame('fixture:evaluation/own', $result['evaluations'][0]['ref']);
        self::assertFalse($result['can_close']);
        self::assertFalse($result['can_approve']);
    }

    public function testEntradaNoPermiteCambiarTenantNiExponerPII(): void
    {
        $input = self::structure();
        $input['tenant_ref'] = 'tenant-b';
        self::rejects(static fn () => self::project('structure', $input));
        $input = self::structure();
        $input['positions'][0]['employee_ref'] = 'persona@example.com';
        self::rejects(static fn () => self::project('structure', $input));
        self::rejects(static fn () => self::project('unknown', self::structure()));
    }

    public function testControladorExigeCsrfOwnerYBranchScopeEnPreview(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../src/Http/Controller/HumanResourcesController.php');
        self::assertIsString($source);
        foreach ([
            'hr/extension/previews/{kind}', "methods: ['POST']",
            '$this->requireCsrf($request);', '$this->access($branchId, \'users.view\');',
            '$this->authorizedBranchScope($branchId, $permission)',
            'Membership::ROLE_OWNER', 'CONDOR_HR_SYNTHETIC_API',
            'CONDOR_PRODUCTION_STAGE', 'construccion',
            'HumanResourcesPreview::project(', 'private, no-store',
        ] as $guard) {
            self::assertStringContainsString($guard, $source);
        }
        // Inspección estática: no equivale a prueba E2E de una UI real.
    }
}

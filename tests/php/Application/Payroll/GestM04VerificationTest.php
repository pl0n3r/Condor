<?php

declare(strict_types=1);

namespace App\Tests\Application\Payroll;

use App\Application\Payroll\PayrollPeriodsProjection;
use App\Application\Payroll\PayrollSurchargesBenefits;
use PHPUnit\Framework\TestCase;

/**
 * Condor #1529: testigos M04 con fixtures ficticios y RRHH desactivado.
 * No certifican nómina legal, emisión DIAN ni operación productiva.
 */
final class GestM04VerificationTest extends TestCase
{
    private const TENANT = 'fixture:tenant/demo';
    private const ENTITY = 'fixture:entity/demo';

    /** @return array<string,mixed> */
    private static function periods(): array
    {
        $scope = ['tenant_ref' => self::TENANT, 'legal_entity_ref' => self::ENTITY];

        return $scope + [
            'periods' => [$scope + [
                'period_ref' => 'fixture:period/oct',
                'start_on' => '2026-10-01', 'end_on' => '2026-10-31',
            ]],
            'concepts' => [$scope + [
                'concept_ref' => 'fixture:concept/base', 'version' => 'fixture:version/v1',
                'kind' => 'devengo', 'amount_decimal' => '123.45',
            ]],
            'novelties' => [$scope + [
                'novelty_ref' => 'fixture:novelty/demo',
                'period_ref' => 'fixture:period/oct', 'concept_ref' => 'fixture:concept/base',
                'quantity_decimal' => '2.50',
            ]],
            'hours' => [$scope + [
                'hours_ref' => 'fixture:hours/demo',
                'period_ref' => 'fixture:period/oct', 'hours_decimal' => '8.25',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function benefits(): array
    {
        return [
            'tenant_ref' => 'tenant-test-demo',
            'company_ref' => 'fixture:company/demo',
            'as_of' => '2026-11-05',
            'parameters' => [
                'version_ref' => 'fixture:policy/v1',
                'effective_from' => '2026-01-01',
                'effective_until' => '2026-12-31',
                'rates' => ['surcharge_bp' => 2500, 'deduction_bp' => 1000, 'benefit_bp' => 500],
            ],
            'items' => [['ref' => 'fixture:payroll/demo', 'base_cents' => '10000']],
        ];
    }

    public function testS01PeriodosValidadosSinCerrarNomina(): void
    {
        $result = PayrollPeriodsProjection::project(self::periods());
        self::assertSame([], $result['errors']);
        self::assertSame('fixture:period/oct', $result['periods'][0]['period_ref']);
        self::assertSame('2026-10-31', $result['periods'][0]['end_on']);
        self::assertSame('REQUIERE_REVISION', $result['periods'][0]['state']);
        self::assertFalse($result['legal_effect']);
        self::assertFalse($result['automatic_approval']);

        $invalid = self::periods();
        $invalid['periods'][0]['end_on'] = '2026-02-30';
        self::assertSame(['INVALID_PERIOD'], PayrollPeriodsProjection::project($invalid)['errors']);
        $duplicate = self::periods();
        $duplicate['periods'][] = $duplicate['periods'][0];
        self::assertSame(['INVALID_OR_DUPLICATE_REFERENCE'], PayrollPeriodsProjection::project($duplicate)['errors']);
    }

    public function testS02ConceptosVersionadosConDecimalesExactos(): void
    {
        $result = PayrollPeriodsProjection::project(self::periods());
        self::assertSame('fixture:version/v1', $result['concepts'][0]['version']);
        self::assertSame(12345, $result['concepts'][0]['amount_hundredths']);
        self::assertSame('devengo', $result['concepts'][0]['kind']);

        $invalid = self::periods();
        $invalid['concepts'][0]['version'] = 'version-real';
        self::assertSame(['INVALID_CONCEPT'], PayrollPeriodsProjection::project($invalid)['errors']);
        $invalid = self::periods();
        $invalid['concepts'][0]['amount_decimal'] = '123.456';
        self::assertSame(['INVALID_CONCEPT'], PayrollPeriodsProjection::project($invalid)['errors']);
    }

    public function testS03NovedadesExigenReferenciasLocalesExistentes(): void
    {
        $result = PayrollPeriodsProjection::project(self::periods());
        self::assertSame(250, $result['novelties'][0]['quantity_hundredths']);
        self::assertSame('fixture:period/oct', $result['novelties'][0]['period_ref']);

        $invalid = self::periods();
        $invalid['novelties'][0]['concept_ref'] = 'fixture:concept/unknown';
        $rejected = PayrollPeriodsProjection::project($invalid);
        self::assertSame(['UNKNOWN_PERIOD_OR_CONCEPT'], $rejected['errors']);
        self::assertSame([], $rejected['novelties']);
    }

    public function testS04HorasRechazanPeriodosAjenosYPrecisionInvalida(): void
    {
        $result = PayrollPeriodsProjection::project(self::periods());
        self::assertSame(825, $result['hours'][0]['hours_hundredths']);

        $invalid = self::periods();
        $invalid['hours'][0]['period_ref'] = 'fixture:period/foreign';
        self::assertSame(['UNKNOWN_PERIOD'], PayrollPeriodsProjection::project($invalid)['errors']);
        $invalid = self::periods();
        $invalid['hours'][0]['hours_decimal'] = '8.257';
        self::assertSame(['INVALID_HOURS'], PayrollPeriodsProjection::project($invalid)['errors']);
    }

    public function testAislamientoTenantYEntidadLegalFallaCerrado(): void
    {
        foreach (['tenant_ref', 'legal_entity_ref'] as $field) {
            $input = self::periods();
            $input['novelties'][0][$field] = 'fixture:'.($field === 'tenant_ref' ? 'tenant' : 'entity').'/foreign';
            $result = PayrollPeriodsProjection::project($input);
            self::assertSame(['INVALID_SCOPE_OR_ROW'], $result['errors']);
            self::assertSame([], $result['periods']);
            self::assertSame([], $result['concepts']);
            self::assertSame([], $result['novelties']);
            self::assertSame([], $result['hours']);
        }
        $input = self::periods();
        $input['concepts'][0]['email'] = 'persona@example.test';
        self::assertSame(['INVALID_SYNTHETIC_INPUT'], PayrollPeriodsProjection::project($input)['errors']);
    }

    public function testS05RecargosCalculadosSoloConParametrosFicticios(): void
    {
        $result = PayrollSurchargesBenefits::project(self::benefits());
        self::assertTrue($result['valid']);
        self::assertSame('fixture:policy/v1', $result['policy_ref']);
        self::assertSame('25.00', $result['slips'][0]['surcharges']);
        self::assertSame('115.00', $result['slips'][0]['net_preview']);

        $invalid = self::benefits();
        $invalid['parameters']['rates']['surcharge_bp'] = 25001;
        self::assertFalse(PayrollSurchargesBenefits::project($invalid)['valid']);
    }

    public function testS06DeduccionesSinLimitesLegalesInventados(): void
    {
        $result = PayrollSurchargesBenefits::project(self::benefits());
        self::assertSame('10.00', $result['slips'][0]['deductions']);

        $invalid = self::benefits();
        $invalid['parameters']['rates']['deduction_bp'] = 10001;
        self::assertFalse(PayrollSurchargesBenefits::project($invalid)['valid']);
    }

    public function testS07PrestacionesSinPretensionDeLiquidacion(): void
    {
        $result = PayrollSurchargesBenefits::project(self::benefits());
        self::assertSame('5.00', $result['slips'][0]['benefits']);
        self::assertTrue($result['slips'][0]['requires_professional_review']);
        self::assertSame('REQUIERE_REVISION', $result['state']);

        $invalid = self::benefits();
        $invalid['as_of'] = '2027-01-01';
        self::assertFalse(PayrollSurchargesBenefits::project($invalid)['valid']);
    }

    public function testS08DesprendibleSoloVistaNoOficial(): void
    {
        $result = PayrollSurchargesBenefits::project(self::benefits());
        self::assertCount(1, $result['slips']);
        self::assertFalse($result['slips'][0]['official']);
        self::assertSame('NO OFICIAL - DATOS SINTETICOS', $result['slips'][0]['watermark']);
        self::assertSame('fixture:payroll/demo', $result['slips'][0]['ref']);

        $invalid = self::benefits();
        $invalid['items'][] = $invalid['items'][0];
        self::assertFalse(PayrollSurchargesBenefits::project($invalid)['valid']);
        self::assertSame([], PayrollSurchargesBenefits::project($invalid)['slips']);
    }

    public function testS09NoExisteEmisionElectronicaActivada(): void
    {
        // Test de NO activación, no de salida electrónica ni acuse DIAN.
        $controller = file_get_contents(__DIR__.'/../../../../src/Http/Controller/PayrollController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("getenv('CONDOR_PAYROLL_SYNTHETIC_API') !== '1'", $controller);
        self::assertStringContainsString("getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'", $controller);
        self::assertStringContainsString('Membership::ROLE_OWNER', $controller);
        self::assertStringContainsString('authorizedBranchScope', $controller);
        self::assertStringContainsString('requireCsrf($request)', $controller);
        self::assertStringContainsString('private, no-store', $controller);
        self::assertStringNotContainsString('payroll/submit', $controller);
        self::assertStringNotContainsString('dian.gov.co', $controller);
    }

    public function testEntradaDeBeneficiosNoAdmitePIINiEmpresaSinFixture(): void
    {
        $invalid = self::benefits();
        $invalid['company_ref'] = 'nit-123456789';
        self::assertFalse(PayrollSurchargesBenefits::project($invalid)['valid']);
        $invalid = self::benefits();
        $invalid['items'][0]['employee_email'] = 'persona@example.test';
        self::assertFalse(PayrollSurchargesBenefits::project($invalid)['valid']);

        $result = PayrollSurchargesBenefits::project(self::benefits());
        self::assertSame('tenant-test-demo', $result['tenant_ref']);
        self::assertSame('fixture:company/demo', $result['company_ref']);
    }

    public function testUiPersistenteConGateOffYEstadosSeguros(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../frontend/admin/PayrollManagement.tsx');
        $app = file_get_contents(__DIR__.'/../../../../frontend/admin/AdminApp.tsx');
        $controller = file_get_contents(__DIR__.'/../../../../src/Http/Controller/AdminController.php');
        $backend = file_get_contents(__DIR__.'/../../../../src/Http/Controller/PayrollController.php');
        $twig = file_get_contents(__DIR__.'/../../../../templates/admin/index.html.twig');
        self::assertIsString($source);
        self::assertIsString($app);
        self::assertIsString($controller);
        self::assertIsString($backend);
        self::assertIsString($twig);

        // El servidor controla la activación. Sin flag/permiso, 0 HTTP.
        foreach ([
            "if (!options.enabled) return { status: 'disabled' };",
            "if (!options.allowed) return { status: 'denied' };",
            "options.companyId === '__unassigned__'",
            'row.tenant_id !== tenant', 'row.company_id !== company',
            'encodeURIComponent(options.branchId)',
            "credentials: 'same-origin'", "'X-CSRF-Token'",
            'expected_version: options.expectedVersion',
            'No hay registros para este filtro.',
            'role="alert"', 'role="status"', 'aria-live="polite"',
        ] as $guard) {
            self::assertStringContainsString($guard, $source);
        }
        self::assertStringContainsString("getenv('PAYROLL_REAL_DATA_ENABLED') === 'true'", $controller);
        self::assertStringContainsString("getenv('PAYROLL_REAL_DATA_ENABLED') === 'true'", $backend);
        self::assertStringContainsString('data-payroll-real-data-enabled', $twig);
        self::assertStringContainsString("dataset.payrollRealDataEnabled === 'true'", $app);
        self::assertStringContainsString('enabled={payrollRealDataEnabled}', $app);
        self::assertStringContainsString('requireCsrf($request)', $backend);
        self::assertStringContainsString('Membership::ROLE_OWNER', $backend);
        foreach (['localStorage', 'sessionStorage', 'indexedDB', 'bankAccount'] as $api) {
            self::assertStringNotContainsString($api, $source);
        }
    }

}

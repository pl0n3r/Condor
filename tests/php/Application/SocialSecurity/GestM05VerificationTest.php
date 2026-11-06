<?php

declare(strict_types=1);

namespace App\Tests\Application\SocialSecurity;

use App\Application\SocialSecurity\SocialSecurityContributionsProjection;
use App\Application\SocialSecurity\SocialSecurityPilaPreparation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Condor #1530 / GEST-M05: pruebas reales de proyecciones, solo fixtures.
 * No acredita API HTTP por submodulo, almacenamiento legal, PILA ni go-live.
 */
final class GestM05VerificationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function contributions(): array
    {
        return [
            'tenant_ref' => 'fixture:tenant/demo',
            'policy' => [
                'version' => 'fixture:policy/demo', 'health_bp' => 400, 'pension_bp' => 1600,
            ],
            'affiliations' => [[
                'tenant_ref' => 'fixture:tenant/demo',
                'employee_ref' => 'fixture:employee/one',
                'affiliation_ref' => 'fixture:affiliation/one', 'base_units' => 10000,
            ]],
            'novelties' => [[
                'tenant_ref' => 'fixture:tenant/demo',
                'employee_ref' => 'fixture:employee/one',
                'novelty_ref' => 'fixture:novelty/one',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function pila(): array
    {
        return [
            'tenant_ref' => 'fixture:tenant/demo',
            'reference_date' => '2026-10-27',
            'supports' => [[
                'tenant_ref' => 'fixture:tenant/demo',
                'employee_ref' => 'fixture:employee/one',
                'support_ref' => 'fixture:support/one',
            ]],
            'alerts' => [[
                'tenant_ref' => 'fixture:tenant/demo',
                'employee_ref' => 'fixture:employee/one',
                'alert_ref' => 'fixture:alert/one', 'due_on' => '2026-10-20',
            ]],
        ];
    }

    public function testS01AfiliacionesSinteticasNoAutorizanTramites(): void
    {
        $result = SocialSecurityContributionsProjection::project(self::contributions());
        self::assertSame('fixture:affiliation/one', $result['rows'][0]['affiliation_ref']);
        self::assertSame('REQUIERE_REVISION', $result['state']);
        self::assertSame('EN_REVISION', $result['rows'][0]['state']);
        self::assertFalse($result['legal_value']);
        self::assertFalse($result['enabled_for_real_data']);

        $foreign = self::contributions();
        $foreign['affiliations'][0]['tenant_ref'] = 'fixture:tenant/other';
        $foreign['novelties'] = [];
        self::assertSame([], SocialSecurityContributionsProjection::project($foreign)['rows']);
    }

    public function testS02NovedadesReferencianAfiliacionDelMismoTenant(): void
    {
        $result = SocialSecurityContributionsProjection::project(self::contributions());
        self::assertSame(['fixture:novelty/one'], $result['rows'][0]['novelties']);

        $foreign = self::contributions();
        $foreign['novelties'][0]['tenant_ref'] = 'fixture:tenant/other';
        self::assertSame([], SocialSecurityContributionsProjection::project($foreign)['rows'][0]['novelties']);

        $orphan = self::contributions();
        $orphan['novelties'][0]['employee_ref'] = 'fixture:employee/missing';
        $this->expectException(InvalidArgumentException::class);
        SocialSecurityContributionsProjection::project($orphan);
    }

    public function testS03AportesSonCalculoIlustrativoVersionado(): void
    {
        $result = SocialSecurityContributionsProjection::project(self::contributions());
        self::assertSame('fixture:policy/demo', $result['policy_version']);
        self::assertSame(400, $result['rows'][0]['health_units']);
        self::assertSame(1600, $result['rows'][0]['pension_units']);
        self::assertFalse($result['legal_value']);

        $bad = self::contributions();
        $bad['policy']['health_bp'] = 10001;
        $this->expectException(InvalidArgumentException::class);
        SocialSecurityContributionsProjection::project($bad);
    }

    public function testS04SoportesRechazanDuplicadosYSuprimenTenantAjeno(): void
    {
        $input = self::pila();
        $foreign = $input['supports'][0];
        $foreign['tenant_ref'] = 'fixture:tenant/other';
        $input['supports'][] = $foreign;
        $result = SocialSecurityPilaPreparation::project($input);
        self::assertCount(1, $result['supports']);
        self::assertSame('REQUIERE_REVISION', $result['supports'][0]['state']);

        $bad = self::pila();
        $bad['supports'][] = $bad['supports'][0];
        $this->expectException(InvalidArgumentException::class);
        SocialSecurityPilaPreparation::project($bad);
    }

    public function testS05AlertasSoloIdentificanCandidatasDeAtencion(): void
    {
        $input = self::pila();
        $later = $input['alerts'][0];
        $later['alert_ref'] = 'fixture:alert/later';
        $later['due_on'] = '2026-11-05';
        $input['alerts'][] = $later;
        $result = SocialSecurityPilaPreparation::project($input);
        self::assertTrue($result['alerts'][1]['attention_candidate']);
        self::assertFalse($result['alerts'][0]['attention_candidate']);
        self::assertSame('EN_REVISION', $result['alerts'][0]['state']);
        self::assertSame('EN_REVISION', $result['alerts'][1]['state']);
        self::assertFalse($result['legal_value']);
    }

    public function testS06PilaEsBorradorNoEnviable(): void
    {
        $result = SocialSecurityPilaPreparation::project(self::pila());
        self::assertSame('BORRADOR_NO_ENVIABLE', $result['pila_example']['state']);
        self::assertSame('fixture:tenant/demo', $result['pila_example']['tenant_ref']);
        self::assertSame(1, $result['pila_example']['support_count']);
        self::assertFalse($result['can_send']);
        self::assertFalse($result['legal_value']);

        $bad = self::pila();
        $bad['alerts'][0]['due_on'] = '2026-02-30';
        $this->expectException(InvalidArgumentException::class);
        SocialSecurityPilaPreparation::project($bad);
    }

    public function testDatosRealesYCamposExtraFallanCerrado(): void
    {
        $bad = self::contributions();
        $bad['affiliations'][0]['employee_ref'] = 'person@example.invalid';
        $this->expectException(InvalidArgumentException::class);
        SocialSecurityContributionsProjection::project($bad);
    }

    public function testContratoHttpExigePermisosCsrfYBanderaApagada(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/src/Http/Controller/SocialSecurityController.php');
        self::assertIsString($source);
        foreach ([
            'authorizedBranchScope($branchId, $permission)',
            "'users.view'", "'users.create'", "'users.update'",
            'Membership::ROLE_OWNER', 'requireCsrf($request)',
            'CONDOR_SOCIAL_SECURITY_SYNTHETIC_API',
            'CONDOR_PRODUCTION_STAGE', "getParameter('kernel.environment')",
            'Cache-Control', 'private, no-store',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
        // Asercion estatica: no reemplaza HTTP autenticado contra Symfony.
    }
}

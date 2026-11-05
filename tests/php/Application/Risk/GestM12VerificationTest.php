<?php

declare(strict_types=1);

namespace App\Tests\Application\Risk;

use App\Application\Risk\RiskApiService;
use App\Application\Risk\RiskRegisterControls;
use App\Domain\Risk\Entity\RiskRecord;
use DomainException;
use PHPUnit\Framework\TestCase;

/** GEST-M12-S04: technological + reputational fixtures, never a GRC approval. */
final class GestM12VerificationTest extends TestCase
{
    private static function scope(string $tenant, string $branch): string
    {
        return hash('sha256', $tenant.'|'.$branch);
    }

    /** @param callable(): mixed $action */
    private static function rejects(callable $action): void
    {
        try {
            $action();
            self::fail('Se aceptó una entrada o transición prohibida.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    /** @return array<string, mixed> */
    private static function record(string $category, string $ref): array
    {
        return [
            'risk_ref' => $ref, 'category' => $category,
            'probability' => 3, 'impact' => 4,
        ];
    }

    public function testS04CategoriasConEscenarioSinteticoYEstadosPendientes(): void
    {
        $scope = self::scope('tenant-a', 'branch-a');
        $technical = RiskApiService::createRecord(
            [], $scope, self::record('tecnologico', 'fixture:risk/tech-a'),
        );
        $reputational = self::record('reputacional', 'fixture:risk/reputation-a');
        $reputational['scenario_ref'] = 'fixture:risk-scenario/brand-synthetic';
        $created = RiskApiService::createRecord($technical['store'], $scope, $reputational);
        self::assertSame('PENDIENTE', $created['record']['state']);
        self::assertSame('reputacional', $created['record']['category']);
        self::assertSame($reputational['scenario_ref'], $created['record']['scenario_ref']);
        self::assertSame(2, count(RiskApiService::listRecords($created['store'], $scope)));
        self::assertSame(
            $technical['record'],
            RiskApiService::viewRecord($created['store'], $scope, 'fixture:risk/tech-a'),
        );
        $reviewed = RiskApiService::changeState(
            $created['store'], $scope, 'fixture:risk/reputation-a', 'REQUIERE_REVISION',
        );
        self::assertSame('REQUIERE_REVISION', $reviewed['record']['state']);
        self::assertSame($reputational['scenario_ref'], $reviewed['record']['scenario_ref']);
        foreach (['APROBADO', 'CERRADO', 'RESUELTO'] as $state) {
            self::rejects(static fn () => RiskApiService::changeState(
                $reviewed['store'], $scope, 'fixture:risk/reputation-a', $state,
            ));
        }
    }

    public function testS04AislamientoPorTenantYSede(): void
    {
        $a = self::scope('tenant-a', 'branch-a');
        $foreignTenant = self::scope('tenant-b', 'branch-a');
        $foreignBranch = self::scope('tenant-a', 'branch-b');
        $payload = self::record('reputacional', 'fixture:risk/reputation-a');
        $payload['scenario_ref'] = 'fixture:risk-scenario/reputation-a';
        $store = RiskApiService::createRecord([], $a, $payload)['store'];
        foreach ([$foreignTenant, $foreignBranch] as $foreign) {
            self::assertSame([], RiskApiService::listRecords($store, $foreign));
            self::assertNull(RiskApiService::viewRecord(
                $store, $foreign, 'fixture:risk/reputation-a',
            ));
            self::rejects(static fn () => RiskApiService::changeState(
                $store, $foreign, 'fixture:risk/reputation-a', 'EN_REVISION',
            ));
        }
    }

    public function testS04EntradasYArchivoFailClosed(): void
    {
        $scope = self::scope('tenant-a', 'branch-a');
        $valid = self::record('reputacional', 'fixture:risk/reputation-a');
        foreach ([
            $valid, // Missing mandatory, typed scenario: legacy denial remains.
            [...$valid, 'scenario_ref' => 'person@example.test'],
            [...$valid, 'scenario_ref' => 'fixture:risk-scenario/123456789'],
            [...$valid, 'scenario_ref' => 'fixture:risk-scenario/Demo'],
            [...$valid, 'scenario_ref' => 'fixture:risk-scenario/demo', 'person' => 'name'],
            [...$valid, 'scenario_ref' => 'fixture:risk-scenario/demo', 'impact' => true],
            [...self::record('tecnologico', 'fixture:risk/tech-a'),
                'scenario_ref' => 'fixture:risk-scenario/demo'],
        ] as $invalid) {
            self::rejects(static fn () => RiskApiService::createRecord([], $scope, $invalid));
        }
        $record = RiskApiService::createRecord([], $scope, [
            ...$valid, 'scenario_ref' => 'fixture:risk-scenario/demo',
        ]);
        self::rejects(static fn () => RiskApiService::createRecord(
            $record['store'], $scope, [...$valid, 'scenario_ref' => 'fixture:risk-scenario/demo'],
        ));
        $tampered = $record['store'];
        $tampered[$scope]['fixture:risk/reputation-a']['identity'] = 'real';
        self::rejects(static fn () => RiskApiService::listRecords($tampered, $scope));
    }

    public function testS04ProyeccionYDominioNoCertificanRiesgo(): void
    {
        $tenant = 'fixture:tenant/company-a';
        $fixture = [
            'tenant_ref' => $tenant,
            'risks' => [[
                'tenant_ref' => $tenant,
                'risk_ref' => 'fixture:risk/reputation-a',
                'category' => 'reputacional', 'probability' => 3,
                'impact' => 4, 'state' => 'PENDIENTE',
            ]],
            'controls' => [],
        ];
        $result = RiskRegisterControls::project($fixture, $tenant);
        self::assertTrue($result['structurally_reviewable']);
        self::assertTrue($result['synthetic_only']);
        self::assertTrue($result['requires_professional_review']);
        self::assertSame(1, $result['categories']['reputacional']['count']);
        self::assertSame(12, $result['risks'][0]['inherent_score']);
        self::assertSame('PENDIENTE', $result['state']);
        self::assertFalse(RiskRegisterControls::project(
            $fixture, 'fixture:tenant/company-b',
        )['structurally_reviewable']);
        $record = RiskRecord::open(
            $tenant, 'fixture:company/demo-a', 'fixture:risk/reputation-a',
            'reputacional', 'fixture:actor/demo-a',
        );
        self::assertSame('PENDIENTE', $record->state());
        self::rejects(static fn () => $record->transition(
            'APROBADO', 'fixture:actor/demo-a', true,
        ));
    }

    public function testS04ContratoDeAccesoHttpDelServidor(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../../src/Http/Controller/RiskController.php',
        );
        self::assertIsString($source);
        foreach ([
            "'settings.view'", "'settings.create'", "'settings.update'",
            '$this->authorizedBranchScope($branchId, $permission)',
            '$this->requireCsrf($request);',
            "'CONDOR_RISK_SYNTHETIC_API'",
            "'CONDOR_PRODUCTION_STAGE'",
            "['risk_ref', 'category', 'probability', 'impact', 'scenario_ref']",
            "hash('sha256', sprintf('%s|%s', ",
            "'synthetic_only' => true",
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        // Source assertions supplement, not replace, authenticated HTTP/UI E2E.
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Application\Governance;

use App\Application\Governance\GovernanceApiService as Service;
use App\Domain\Governance\Entity\GovernanceRecord;
use DomainException;
use PHPUnit\Framework\TestCase;

/** M15: only synthetic fixtures, scoped by tenant and branch; no legal approval. */
final class GestM15VerificationTest extends TestCase
{
    private const SCOPE_A = 'tenant-fixture-a|branch-fixture-a';
    private const SCOPE_B = 'tenant-fixture-b|branch-fixture-a';

    private static function scope(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function rejects(callable $action): void
    {
        try {
            $action();
            self::fail('Una operación inválida fue aceptada.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    private static function verifyKind(string $kind): void
    {
        $a = self::scope(self::SCOPE_A);
        $b = self::scope(self::SCOPE_B);
        $otherBranch = self::scope('tenant-fixture-a|branch-fixture-b');
        $ref = 'fixture:'.$kind.'/demo';
        $created = Service::createRecord([], $a, ['kind' => $kind, 'ref' => $ref]);
        $store = $created['store'];
        self::assertSame(['ref' => $ref, 'kind' => $kind, 'state' => 'REQUIERE_REVISION'], $created['record']);
        self::assertSame([$created['record']], Service::listRecords($store, $a));
        self::assertSame($created['record'], Service::viewRecord($store, $a, $ref));
        self::assertSame([], Service::listRecords($store, $b));
        self::assertSame([], Service::listRecords($store, $otherBranch));
        self::assertNull(Service::viewRecord($store, $b, $ref));
        self::assertNull(Service::viewRecord($store, $otherBranch, $ref));
        self::rejects(static fn () => Service::changeState($store, $b, $ref, 'EN_REVISION'));
        self::rejects(static fn () => Service::changeState($store, $otherBranch, $ref, 'EN_REVISION'));
        self::rejects(static fn () => Service::createRecord($store, $a, ['kind' => $kind, 'ref' => $ref]));
        self::rejects(static fn () => Service::createRecord($store, $a, ['kind' => $kind, 'ref' => 'fixture:body/wrong-kind']));
        $review = Service::changeState($store, $a, $ref, 'EN_REVISION');
        self::assertSame('EN_REVISION', $review['record']['state']);
        foreach (['APROBADO', 'CERRADO', 'AUTORIZADO', 'FIRMADO'] as $forbidden) {
            self::rejects(static fn () => Service::changeState($review['store'], $a, $ref, $forbidden));
        }
        self::assertSame('EN_REVISION', Service::viewRecord($review['store'], $a, $ref)['state']);
    }

    public function testS01SociosSinteticosAislados(): void
    {
        self::verifyKind('shareholder');
        self::verifyDomainInstrument('socio');
    }

    public function testS04FacultadesSinDelegacionLegal(): void
    {
        self::verifyKind('faculty');
        self::verifyDomainInstrument('facultad');
    }

    public function testS07AutorizacionesSinAprobacionLegal(): void
    {
        self::verifyKind('authorization');
        self::verifyDomainInstrument('autorizacion');
    }

    public function testS08DecisionesSinCierreAutomatico(): void
    {
        self::verifyKind('decision');
        self::verifyDomainInstrument('decision');
    }

    private static function verifyDomainInstrument(string $instrument): void
    {
        $record = GovernanceRecord::register(
            'fixture:tenant/demo-a',
            'fixture:company/demo-a',
            'fixture:governance/demo-a',
            $instrument,
            'fixture:actor/demo-a',
        );
        self::assertSame('PENDIENTE', $record->state());
        self::assertSame($instrument, $record->toRow()['category']);
        self::rejects(static fn () => $record->requestState('APROBADO', 'fixture:actor/demo-a', true));
        self::rejects(static fn () => $record->requestState('REQUIERE_REVISION', 'fixture:actor/demo-a', false));
        self::rejects(static fn () => GovernanceRecord::register(
            'fixture:tenant/demo-b', 'someone@example.com',
            'fixture:governance/demo-a', $instrument, 'fixture:actor/demo-a',
        ));
    }

    public function testNegativosNoAdmitenDatosRealesNiCamposExtra(): void
    {
        $scope = self::scope(self::SCOPE_A);
        foreach ([
            ['kind' => 'shareholder', 'ref' => 'persona@example.com'],
            ['kind' => 'faculty', 'ref' => '12345678'],
            ['kind' => 'authorization', 'ref' => 'https://example.com/acta'],
            ['kind' => 'decision', 'ref' => 'fixture:decision/demo', 'name' => 'Persona real'],
            ['kind' => 'decision', 'ref' => 'fixture:decision/demo', 'approved' => true],
            ['kind' => 'unknown', 'ref' => 'fixture:unknown/demo'],
            ['kind' => 'faculty', 'ref' => 'fixture:faculty/Demo'],
            ['kind' => 'authorization', 'ref' => 'fixture:authorization/demo/extra'],
        ] as $payload) {
            self::rejects(static fn () => Service::createRecord([], $scope, $payload));
        }
        self::rejects(static fn () => Service::listRecords([
            $scope => [['kind' => 'decision', 'ref' => 'fixture:decision/demo', 'state' => 'APROBADO']],
        ], $scope));
        self::rejects(static fn () => Service::listRecords([
            $scope => [['kind' => 'decision', 'ref' => 'fixture:decision/demo', 'state' => 'REQUIERE_REVISION', 'identity' => 'real']],
        ], $scope));
    }

    public function testContratoControladorAutorizacionServidorYBandera(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../src/Http/Controller/GovernanceController.php');
        self::assertIsString($source);
        foreach ([
            "methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']",
            "'inventory.view'", "'inventory.create'", "'inventory.update'",
            '$this->authorizedBranchScope($branchId,',
            '$this->requireCsrf($request);',
            '$this->syntheticOnly();',
            "'CONDOR_GOVERNANCE_SYNTHETIC_API'",
            "'CONDOR_PRODUCTION_STAGE'",
            "'construccion'",
            "hash('sha256', \$tenantId.'|'.\$branchId)",
            '$this->recordAudit(',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
        self::assertSame(2, substr_count($source, '$this->requireCsrf($request);'));
        self::assertSame(4, substr_count($source, '$this->authorizedBranchScope('));
        self::assertSame(4, substr_count($source, '$this->syntheticOnly();'));
        // Inspección del controlador: NO sustituye requests HTTP autenticadas ni una UI conectada.
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Application\Quality;

use App\Application\Quality\QualityApiService;
use App\Domain\Quality\Entity\QualityRecord;
use DomainException;
use PHPUnit\Framework\TestCase;

/** GEST-M13-S03: fixtures únicamente, sin PII ni cierre automático. */
final class GestM13VerificationTest extends TestCase
{
    private static function scope(string $tenant, string $branch): string
    {
        return hash('sha256', $tenant.'|'.$branch);
    }

    private static function rejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Una operación insegura fue aceptada.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    public function testS03QuejasInternasAisladasPorTenantYSede(): void
    {
        $scope = self::scope('tenant-a', 'branch-a');
        $otherTenant = self::scope('tenant-b', 'branch-a');
        $otherBranch = self::scope('tenant-a', 'branch-b');
        $payload = [
            'record_ref' => 'fixture:quality-record/queja-demo',
            'source_ref' => 'fixture:quality-source/reporte-demo',
            'kind' => 'QUEJA_INTERNA',
        ];
        $created = QualityApiService::createRecord([], $scope, $payload);
        $ref = $payload['record_ref'];
        self::assertSame($payload + ['state' => 'PENDIENTE'], $created['record']);
        $store = $created['store'];
        self::assertSame([$created['record']], QualityApiService::listRecords($store, $scope));
        self::assertSame($created['record'], QualityApiService::viewRecord($store, $scope, $ref));

        foreach ([$otherTenant, $otherBranch] as $foreign) {
            self::assertSame([], QualityApiService::listRecords($store, $foreign));
            self::assertNull(QualityApiService::viewRecord($store, $foreign, $ref));
            self::rejects(static fn () => QualityApiService::changeState(
                $store, $foreign, $ref, 'EN_REVISION',
            ));
        }
        self::rejects(static fn () => QualityApiService::createRecord($store, $scope, $payload));
        $reviewed = QualityApiService::changeState($store, $scope, $ref, 'EN_REVISION');
        self::assertSame('EN_REVISION', $reviewed['record']['state']);
        $escalated = QualityApiService::changeState(
            $reviewed['store'], $scope, $ref, 'REQUIERE_REVISION',
        );
        self::assertSame('REQUIERE_REVISION', $escalated['record']['state']);
        foreach (['APROBADO', 'CERRADO', 'RESUELTO', 'VERIFICADO'] as $closed) {
            self::rejects(static fn () => QualityApiService::changeState(
                $escalated['store'], $scope, $ref, $closed,
            ));
        }
    }

    public function testS03EntradasRealesYCamposExtraRechazados(): void
    {
        $scope = self::scope('tenant-a', 'branch-a');
        $base = [
            'record_ref' => 'fixture:quality-record/queja-demo',
            'source_ref' => 'fixture:quality-source/reporte-demo',
            'kind' => 'QUEJA_INTERNA',
        ];
        foreach ([
            ['record_ref' => 'persona@example.com'],
            ['record_ref' => 'fixture:quality-record/12345678'],
            ['source_ref' => 'https://example.com/archivo'],
            ['source_ref' => 'fixture:quality-source/12345678'],
            ['kind' => 'RECLAMO_REAL'],
            ['description' => 'dato personal'],
            ['approved' => true],
        ] as $bad) {
            self::rejects(static fn () => QualityApiService::createRecord(
                [], $scope, array_replace($base, $bad),
            ));
        }
        self::rejects(static fn () => QualityApiService::createRecord([], 'scope-invalido', $base));
        self::rejects(static fn () => QualityApiService::viewRecord([], $scope, 'persona@example.com'));
        self::rejects(static fn () => QualityApiService::listRecords([
            $scope => [$base['record_ref'] => $base + ['state' => 'CERRADO']],
        ], $scope));
        self::rejects(static fn () => QualityApiService::listRecords([
            $scope => [['record_ref' => $base['record_ref']]],
        ], $scope));
    }

    public function testS03EntidadInmutableNoCertificaNiCierra(): void
    {
        $record = QualityRecord::open(
            'fixture:tenant/empresa-a',
            'fixture:company/sede-a',
            'fixture:quality/queja-demo',
            'queja_interna',
            'fixture:actor/revisor-a',
        );
        self::assertSame('queja_interna', $record->snapshot()['category']);
        self::assertSame('PENDIENTE', $record->state());
        self::rejects(static fn () => $record->transition(
            'REQUIERE_REVISION', 'fixture:actor/revisor-a', false,
        ));
        $next = $record->transition('REQUIERE_REVISION', 'fixture:actor/revisor-a', true);
        self::assertSame('REQUIERE_REVISION', $next->state());
        self::assertSame(2, $next->version());
        self::assertSame($record->id(), $next->previousId());
        self::assertSame('PENDIENTE', $record->state());
        self::rejects(static fn () => $next->transition('CERRADO', 'fixture:actor/revisor-a', true));
        self::rejects(static fn () => QualityRecord::open(
            'fixture:tenant/empresa-a', 'persona@example.com',
            'fixture:quality/queja-demo', 'queja_interna', 'fixture:actor/revisor-a',
        ));
    }

    public function testS03ControladorConGuardasAutorizacionServidorYCsrf(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../src/Http/Controller/QualityController.php');
        self::assertIsString($source);
        foreach ([
            "methods: ['GET']", "methods: ['POST']", "methods: ['PATCH']",
            "'settings.view'", "'settings.create'", "'settings.update'",
            '$this->authorizedBranchScope($branchId, $permission)',
            '$this->requireCsrf($request);',
            "getenv('CONDOR_QUALITY_SYNTHETIC_API') !== '1'",
            "getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'",
            "hash('sha256', \$tenant->id().'|'.\$branch->id())",
            '$this->auditSynthetic(',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
        self::assertSame(1, substr_count($source, '$this->requireCsrf($request);'));
        // Estático: la integración HTTP real y los estados de red de UI son brecha aparte.
    }
}

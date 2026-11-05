<?php

declare(strict_types=1);

namespace App\Tests\Application\Finance;

use App\Application\Finance\FinanceReconciliationBudget;
use PHPUnit\Framework\TestCase;

/** GEST-M07-S06: synthetic-only review; no real close or accounting attestation. */
final class GestM07ClosureVerificationTest extends TestCase
{
    private const TENANT = 'fixture:tenant/current';
    private const ENTITY = 'fixture:legal-entity/current';

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__.'/fixtures/closure-s06.json');
        if ($raw === false) {
            throw new \RuntimeException('Fixture de cierres sintéticos no encontrada.');
        }
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture de cierres sintéticos inválida.');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private static function project(array $payload): array
    {
        return FinanceReconciliationBudget::project($payload, self::TENANT, self::ENTITY);
    }

    public function testS06ConciliadoSoloEsCandidatoDeRevision(): void
    {
        $out = self::project(self::fixture());
        self::assertSame('REQUIERE_REVISION', $out['state']);
        self::assertSame('COP', $out['currency']);
        self::assertTrue($out['synthetic_only']);
        self::assertSame(1, $out['matched_candidate_count']);
        self::assertTrue($out['periods'][0]['review_candidate']);
        self::assertFalse($out['periods'][0]['can_close']);
        self::assertFalse($out['can_close']);
        self::assertFalse($out['can_approve']);
    }

    public function testS06SinEvidenciaOConciliacionNoHayCandidato(): void
    {
        $empty = self::fixture();
        $empty['ledger_entries'] = [];
        $empty['bank_movements'] = [];
        $empty['budgets'] = [];
        self::assertFalse(self::project($empty)['periods'][0]['review_candidate']);

        $broken = self::fixture();
        $broken['bank_movements'][0]['amount_decimal'] = '12.31';
        $out = self::project($broken);
        self::assertFalse($out['periods'][0]['review_candidate']);
        self::assertSame(1, $out['unmatched_movement_count']);
        self::assertFalse($out['can_close']);

        $other = self::fixture();
        $other['periods'][] = [
            'period_ref' => 'fixture:finance-period/september',
            'tenant_ref' => self::TENANT,
            'legal_entity_ref' => self::ENTITY,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'state' => 'BORRADOR',
        ];
        $periods = self::project($other)['periods'];
        self::assertCount(2, $periods);
        self::assertFalse($periods[1]['review_candidate']);
        self::assertFalse($periods[1]['can_close']);
    }

    public function testS06RechazaTenantAjenoEstadosYDatosNoFicticios(): void
    {
        $foreign = self::fixture();
        $foreign['periods'][0]['tenant_ref'] = 'fixture:tenant/other';
        self::assertSame([], self::project($foreign)['periods']);
        self::assertSame([], FinanceReconciliationBudget::project(
            self::fixture(), 'fixture:tenant/other', self::ENTITY,
        )['periods']);

        $closed = self::fixture();
        $closed['periods'][0]['state'] = 'CERRADO';
        self::assertSame([], self::project($closed)['periods']);

        $pii = self::fixture();
        $pii['customer_email'] = 'customer@example.test';
        self::assertSame([], self::project($pii)['periods']);

        $incorrect = self::fixture();
        $incorrect['ledger_entries'][0]['amount_decimal'] = 12.30;
        self::assertSame([], self::project($incorrect)['periods']);
    }

    public function testEndpointProtegidoYLimitadoAFixtures(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/src/Http/Controller/FinanceController.php');
        self::assertIsString($source);
        $method = explode('public function previewClosure', $source, 2)[1] ?? '';
        $method = explode('private function access', $method, 2)[0];
        self::assertStringContainsString('/finance/closures/preview', $source);
        self::assertStringContainsString("'api_finance_closure_preview', methods: ['POST']", $source);
        self::assertStringContainsString("'settings.update', true", $method);
        self::assertStringContainsString('FinanceReconciliationBudget::project(', $method);
        self::assertStringContainsString("'fixture:tenant/current'", $method);
        self::assertStringContainsString("'fixture:legal-entity/current'", $method);
        self::assertStringContainsString('authorizedBranchScope($branchId, $permission)', $source);
        self::assertStringContainsString('requireCsrf($request)', $source);
        self::assertStringContainsString('CONDOR_FINANCE_SYNTHETIC_API', $source);
        self::assertStringContainsString('CONDOR_PRODUCTION_STAGE', $source);
        self::assertStringNotContainsString('->persist(', $method);
    }
}

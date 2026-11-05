<?php

declare(strict_types=1);

namespace App\Tests\Application\Contracts;

use App\Application\Contracts\ContractApiService;
use App\Application\Contracts\ContractLifecycleProjection;
use App\Application\Contracts\ContractTemplatesProjection;
use DomainException;
use PHPUnit\Framework\TestCase;

/** M02 synthetic witnesses only; real labour processing and live UI remain disabled. */
final class GestM02PreviewTest extends TestCase
{
    /** @return array{tenant_ref:string,company_ref:string} */
    private static function scope(): array
    {
        return ['tenant_ref' => 'tenant-test-alpha', 'company_ref' => 'fixture:company/demo'];
    }

    /** @return array<string,mixed> */
    private static function templates(): array
    {
        $scope = self::scope();
        return [
            ...$scope,
            'templates' => [$scope + [
                'ref' => 'fixture:template/basic', 'reviewer_ref' => 'fixture:reviewer/demo',
                'version' => 2,
            ]],
            'contracts' => [$scope + [
                'ref' => 'fixture:contract/basic', 'template_ref' => 'fixture:template/basic',
                'template_version' => 2, 'worker_ref' => 'fixture:worker/demo',
            ]],
            'addenda' => [$scope + [
                'ref' => 'fixture:addendum/adjustment', 'contract_ref' => 'fixture:contract/basic',
                'reason_key' => 'SCOPE_CHANGE',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function lifecycle(): array
    {
        $scope = self::scope();
        return [
            ...$scope, 'reference_date' => '2026-11-05',
            'contracts' => [$scope + [
                'ref' => 'fixture:contract/basic', 'worker_ref' => 'fixture:worker/demo',
                'start_on' => '2026-01-01', 'end_on' => '2026-11-20',
            ]],
            'signature_evidence' => [$scope + [
                'ref' => 'fixture:signature-evidence/draft',
                'contract_ref' => 'fixture:contract/basic',
                'reviewer_ref' => 'fixture:reviewer/demo', 'captured_on' => '2026-01-02',
            ]],
            'renewals' => [$scope + [
                'ref' => 'fixture:renewal/proposal', 'contract_ref' => 'fixture:contract/basic',
                'reviewer_ref' => 'fixture:reviewer/demo', 'proposed_end_on' => '2027-01-31',
            ]],
            'terminations' => [$scope + [
                'ref' => 'fixture:termination/draft', 'contract_ref' => 'fixture:contract/basic',
                'reviewer_ref' => 'fixture:reviewer/demo', 'effective_on' => '2026-11-20',
                'reason_key' => 'TERM_REVIEW',
            ]],
            'settlement_drafts' => [$scope + [
                'ref' => 'fixture:settlement-draft/estimate',
                'termination_ref' => 'fixture:termination/draft',
                'reviewer_ref' => 'fixture:reviewer/demo',
            ]],
        ];
    }

    public function testS01PlantillaVersionadaRequiereRevision(): void
    {
        $result = ContractTemplatesProjection::project(self::templates());
        self::assertTrue($result['valid']);
        self::assertSame(2, $result['templates'][0]['version']);
        self::assertTrue($result['templates'][0]['review_required']);
        self::assertSame('REQUIERE_REVISION', $result['templates'][0]['state']);

        $input = self::templates();
        $input['templates'][0]['version'] = 0;
        self::assertFalse(ContractTemplatesProjection::project($input)['valid']);
    }

    public function testS02ContratoRequiereVersionDePlantillaYNoValidaFirma(): void
    {
        $result = ContractTemplatesProjection::project(self::templates());
        self::assertTrue($result['valid']);
        self::assertSame('fixture:template/basic', $result['contracts'][0]['template_ref']);
        self::assertFalse($result['contracts'][0]['signature_validated']);

        $input = self::templates();
        $input['contracts'][0]['template_version'] = 1;
        self::assertFalse(ContractTemplatesProjection::project($input)['valid']);
    }

    public function testS03OtrosiRechazaContratoAjenoYMotivoInvalido(): void
    {
        $result = ContractTemplatesProjection::project(self::templates());
        self::assertSame('fixture:contract/basic', $result['addenda'][0]['contract_ref']);
        self::assertTrue($result['addenda'][0]['review_required']);

        $input = self::templates();
        $input['addenda'][0]['contract_ref'] = 'fixture:contract/other';
        self::assertFalse(ContractTemplatesProjection::project($input)['valid']);
        $input = self::templates();
        $input['addenda'][0]['reason_key'] = 'AUTOMATIC_APPROVAL';
        self::assertFalse(ContractTemplatesProjection::project($input)['valid']);
    }

    public function testS04EvidenciaFicticiaNoCertificaFirma(): void
    {
        $result = ContractLifecycleProjection::project(self::lifecycle());
        self::assertTrue($result['valid']);
        self::assertTrue($result['signature_evidence'][0]['synthetic_evidence_only']);
        self::assertFalse($result['signature_evidence'][0]['signature_validated']);
        $input = self::lifecycle();
        $input['signature_evidence'][0]['tenant_ref'] = 'tenant-test-foreign';
        self::assertFalse(ContractLifecycleProjection::project($input)['valid']);
    }

    public function testS05RenovacionSoloProponeYRechazaFechaAnterior(): void
    {
        $result = ContractLifecycleProjection::project(self::lifecycle());
        self::assertSame('2027-01-31', $result['renewals'][0]['proposed_end_on']);
        self::assertFalse($result['renewals'][0]['applied']);
        self::assertFalse($result['renewals'][0]['notification_sent']);

        $input = self::lifecycle();
        $input['renewals'][0]['proposed_end_on'] = '2026-11-19';
        self::assertFalse(ContractLifecycleProjection::project($input)['valid']);
    }

    public function testS06AlertaEsCandidataYNoNotificacionReal(): void
    {
        $result = ContractLifecycleProjection::project(self::lifecycle());
        self::assertTrue($result['contracts'][0]['renewal_attention_candidate']);
        self::assertFalse($result['renewals'][0]['notification_sent']);
        $input = self::lifecycle();
        $input['contracts'][0]['end_on'] = '2027-02-01';
        $input['renewals'][0]['proposed_end_on'] = '2027-03-01';
        self::assertFalse(ContractLifecycleProjection::project($input)['contracts'][0]['renewal_attention_candidate']);
    }

    public function testS07TerminacionExigeMotivoYNoCierraContrato(): void
    {
        $result = ContractLifecycleProjection::project(self::lifecycle());
        self::assertFalse($result['terminations'][0]['contract_closed']);
        self::assertFalse($result['contracts'][0]['closed']);
        self::assertSame('REQUIERE_REVISION', $result['terminations'][0]['state']);

        $input = self::lifecycle();
        $input['terminations'][0]['reason_key'] = 'DESPIDO_AUTOMATICO';
        self::assertFalse(ContractLifecycleProjection::project($input)['valid']);
    }

    public function testS08LiquidacionNoCalculaNiApruebaValoresLegales(): void
    {
        $result = ContractLifecycleProjection::project(self::lifecycle());
        self::assertTrue($result['valid']);
        self::assertSame('SIN_VALOR_LEGAL', $result['settlement_drafts'][0]['notice']);
        self::assertNull($result['settlement_drafts'][0]['amount_cop']);
        self::assertFalse($result['settlement_drafts'][0]['approved']);

        $input = self::lifecycle();
        $input['settlement_drafts'][0]['termination_ref'] = 'fixture:termination/foreign';
        self::assertFalse(ContractLifecycleProjection::project($input)['valid']);
    }

    public function testRechazaTenantCruzadoYCargaConPii(): void
    {
        $input = self::templates();
        $input['contracts'][0]['tenant_ref'] = 'tenant-test-foreign';
        self::assertFalse(ContractTemplatesProjection::project($input)['valid']);
        $input = self::lifecycle();
        $input['contracts'][0]['worker_ref'] = 'person@example.com';
        self::assertFalse(ContractLifecycleProjection::project($input)['valid']);
        $input = self::lifecycle();
        $input['contracts'][0]['salary'] = 1000000;
        self::assertFalse(ContractLifecycleProjection::project($input)['valid']);
    }

    public function testApiAislaAmbitosYRestringeEstados(): void
    {
        $alpha = hash('sha256', 'tenant:alpha|company:demo|branch:one');
        $beta = hash('sha256', 'tenant:beta|company:demo|branch:one');
        $result = ContractApiService::createRecord([], $alpha, [
            'kind' => 'contract', 'ref' => 'fixture:contract/test',
        ]);
        self::assertSame('REQUIERE_REVISION', $result['record']['state']);
        self::assertCount(1, ContractApiService::listRecords($result['store'], $alpha));
        self::assertSame([], ContractApiService::listRecords($result['store'], $beta));
        self::assertNull(ContractApiService::viewRecord($result['store'], $beta, 'fixture:contract/test'));

        $this->expectException(DomainException::class);
        ContractApiService::changeState($result['store'], $alpha, 'fixture:contract/test', 'APROBADO');
    }

    public function testApiRechazaIdentificadoresRealesYSobrecargaDeCampos(): void
    {
        $scope = hash('sha256', 'tenant:alpha|company:demo|branch:one');
        $this->expectException(DomainException::class);
        ContractApiService::createRecord([], $scope, [
            'kind' => 'contract', 'ref' => 'fixture:contract/demo',
            'worker_email' => 'persona@example.com',
        ]);
    }

    public function testControllerMantieneFronteraSinteticaYAutorizacion(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/src/Http/Controller/ContractsController.php');
        self::assertIsString($source);
        self::assertStringContainsString('requireSyntheticEnvironment()', $source);
        self::assertStringContainsString('authorizedBranchScope($branchId, $permission)', $source);
        self::assertStringContainsString('requireCsrf($request)', $source);
        self::assertStringContainsString("getenv('CONDOR_CONTRACTS_SYNTHETIC_API')", $source);
        self::assertStringContainsString("'inventory.view'", $source);
        self::assertStringContainsString("'inventory.create'", $source);
        self::assertStringContainsString("'inventory.update'", $source);
    }
}

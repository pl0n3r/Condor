<?php

declare(strict_types=1);

namespace App\Tests\Application\Payroll;

use App\Application\Payroll\ElectronicPayrollReadiness;
use PHPUnit\Framework\TestCase;

/**
 * #1634 · GEST-M04-S09: offline synthetic contract, not electronic payroll issuance.
 * This suite must never access providers, personal records or DIAN.
 */
final class ElectronicPayrollReadinessTest extends TestCase
{
    private const TENANT = 'fixture:tenant/demo';
    private const COMPANY = 'fixture:company/demo';
    private const DOCUMENT = 'fixture:document/demo';

    /** @return array<string, mixed> */
    private static function syntheticInput(): array
    {
        return [
            'tenant_ref' => self::TENANT,
            'company_ref' => self::COMPANY,
            'synthetic_document_ref' => self::DOCUMENT,
            'synthetic' => true,
            'mode' => 'offline',
        ];
    }

    /** @param array<string, mixed> $plan */
    private static function assertAlwaysDisabled(array $plan): void
    {
        self::assertSame('electronic-payroll-readiness-v1', $plan['contract']);
        self::assertSame('NO_HABILITADO', $plan['state']);
        self::assertSame('PENDIENTE_REVISION', $plan['review_state']);
        self::assertFalse($plan['production_enabled']);
        self::assertFalse($plan['transmitted']);
        self::assertFalse($plan['provider_calls']);
        self::assertNull($plan['acknowledgement']);
        self::assertFalse($plan['legal_effect']);
        self::assertIsArray($plan['errors']);
    }

    public function testOnlySyntheticOfflinePreviewIsAllowedAndNeverClaimsIssuance(): void
    {
        $plan = ElectronicPayrollReadiness::plan(self::syntheticInput(), self::TENANT, self::COMPANY, true);
        self::assertAlwaysDisabled($plan);
        self::assertSame([], $plan['errors']);

        $explicitNoSend = self::syntheticInput();
        $explicitNoSend['request_send'] = false;
        self::assertSame([], ElectronicPayrollReadiness::plan($explicitNoSend, self::TENANT, self::COMPANY, true)['errors']);
    }

    public function testRejectsTenantCrossingWithoutLeakingIdentifiers(): void
    {
        $plan = ElectronicPayrollReadiness::plan(self::syntheticInput(), 'fixture:tenant/foreign');
        self::assertAlwaysDisabled($plan);
        self::assertContains('TENANT_MISMATCH', $plan['errors']);
        $response = json_encode($plan, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::DOCUMENT, $response);
        self::assertStringNotContainsString(self::TENANT, $response);
    }

    public function testRejectsCrossCompanyScopeAndUnauthorizedActor(): void
    {
        $payload = self::syntheticInput();
        $otherCompany = ElectronicPayrollReadiness::plan(
            $payload, self::TENANT, 'fixture:company/foreign', true,
        );
        self::assertAlwaysDisabled($otherCompany);
        self::assertContains('COMPANY_MISMATCH', $otherCompany['errors']);
        self::assertStringNotContainsString(
            self::COMPANY, json_encode($otherCompany, JSON_THROW_ON_ERROR),
        );

        $unauthorized = ElectronicPayrollReadiness::plan($payload, self::TENANT, self::COMPANY);
        self::assertAlwaysDisabled($unauthorized);
        self::assertContains('PERMISSION_DENIED', $unauthorized['errors']);

        $invalidActorCompany = ElectronicPayrollReadiness::plan(
            $payload, self::TENANT, '900123456', true,
        );
        self::assertAlwaysDisabled($invalidActorCompany);
        self::assertContains('COMPANY_MISMATCH', $invalidActorCompany['errors']);
    }

    public function testRejectsPersonalDataFieldsAndNonSyntheticPayloads(): void
    {
        $personal = self::syntheticInput();
        $personal['employee_name'] = 'PERSONA_DE_PRUEBA';
        $plan = ElectronicPayrollReadiness::plan($personal, self::TENANT, self::COMPANY, true);
        self::assertAlwaysDisabled($plan);
        self::assertNotEmpty($plan['errors']);
        self::assertStringNotContainsString('PERSONA_DE_PRUEBA', json_encode($plan, JSON_THROW_ON_ERROR));

        $real = self::syntheticInput();
        $real['synthetic'] = false;
        self::assertNotEmpty(ElectronicPayrollReadiness::plan($real, self::TENANT, self::COMPANY, true)['errors']);
    }

    public function testRejectsLiveModeOrAttemptedTransmission(): void
    {
        $live = self::syntheticInput();
        $live['mode'] = 'live';
        $attempt = self::syntheticInput();
        $attempt['request_send'] = true;

        foreach ([$live, $attempt] as $input) {
            $plan = ElectronicPayrollReadiness::plan($input, self::TENANT, self::COMPANY, true);
            self::assertAlwaysDisabled($plan);
            self::assertNotEmpty($plan['errors']);
        }
    }

    public function testRejectsMissingAndNonFixtureScopeOrMalformedTypes(): void
    {
        $missing = self::syntheticInput();
        unset($missing['company_ref']);
        $foreign = self::syntheticInput();
        $foreign['company_ref'] = '12345';
        $wrongType = self::syntheticInput();
        $wrongType['synthetic_document_ref'] = ['fixture:document/demo'];
        $empty = self::syntheticInput();
        $empty['tenant_ref'] = '';

        foreach ([$missing, $foreign, $wrongType, $empty] as $input) {
            $plan = ElectronicPayrollReadiness::plan($input, self::TENANT, self::COMPANY, true);
            self::assertAlwaysDisabled($plan);
            self::assertNotEmpty($plan['errors']);
        }
    }

    public function testUnexpectedFieldsCannotEnablePublication(): void
    {
        $payload = self::syntheticInput();
        $payload['provider_token'] = 'FAKE_SECRET_FOR_TEST';
        $plan = ElectronicPayrollReadiness::plan($payload, self::TENANT, self::COMPANY, true);
        self::assertAlwaysDisabled($plan);
        self::assertNotEmpty($plan['errors']);
        self::assertStringNotContainsString('FAKE_SECRET_FOR_TEST', json_encode($plan, JSON_THROW_ON_ERROR));
    }
}

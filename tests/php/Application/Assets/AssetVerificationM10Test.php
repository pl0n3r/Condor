<?php

declare(strict_types=1);

namespace App\Tests\Application\Assets;

use App\Application\Assets\AssetApiService;
use App\Application\Assets\AssetRegisterLifecycle;
use App\Domain\Assets\Entity\AssetRecord;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Persistence\AssetRecordRepository;
use Doctrine\DBAL\Connection;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Condor #1535: executable M10 responsibility/evidence slice with synthetic input.
 * The UI and real-tenant HTTP E2E are separate from this offline contract.
 */
final class AssetVerificationM10Test extends TestCase
{
    /** @return array<string,mixed> */
    private static function fixture(string $action = 'ASIGNAR'): array
    {
        return [
            'tenant_ref' => 'tenant-test-alpha',
            'company_ref' => 'fixture:company/headquarters',
            'as_of' => '2026-11-05',
            'assets' => [[
                'tenant_ref' => 'tenant-test-alpha',
                'company_ref' => 'fixture:company/headquarters',
                'asset_ref' => 'fixture:asset/monitor',
                'responsible_ref' => 'fixture:responsible/agent',
                'warranty_until' => '2026-11-06',
                'maintenance_on' => '2026-11-05',
                'action' => $action,
                'evidence_ref' => $action === 'SOLICITAR_BAJA'
                    ? 'fixture:evidence/signed-check' : null,
            ]],
        ];
    }

    public function testResponsibleRefRequiresSyntheticIdentityAndCorrectScope(): void
    {
        $valid = AssetRegisterLifecycle::project(self::fixture());
        self::assertTrue($valid['valid']);
        self::assertSame('tenant-test-alpha', $valid['tenant_ref']);
        self::assertSame('fixture:company/headquarters', $valid['company_ref']);
        self::assertSame('ASIGNADO', $valid['assets'][0]['state']);
        self::assertSame('fixture:responsible/agent', $valid['assets'][0]['responsible_ref']);
        self::assertTrue($valid['assets'][0]['maintenance_due']);
        self::assertFalse($valid['assets'][0]['can_approve']);
        self::assertFalse($valid['assets'][0]['can_close']);

        foreach ([null, '', 'other@example.test', 'fixture:responsible/../other'] as $invalid) {
            $fixture = self::fixture();
            $fixture['assets'][0]['responsible_ref'] = $invalid;
            self::assertFalse(AssetRegisterLifecycle::project($fixture)['valid']);
        }

        foreach (['tenant_ref' => 'tenant-test-other', 'company_ref' => 'fixture:company/other'] as $field => $foreign) {
            $fixture = self::fixture();
            $fixture['assets'][0][$field] = $foreign;
            $scoped = AssetRegisterLifecycle::project($fixture);
            self::assertTrue($scoped['valid']);
            self::assertSame([], $scoped['assets'], 'Unrelated tenant/company must not leak assets.');
        }

        $duplicate = self::fixture();
        $duplicate['assets'][] = $duplicate['assets'][0];
        self::assertFalse(AssetRegisterLifecycle::project($duplicate)['valid']);
    }

    public function testEvidenceIsMandatoryForReviewableDisposalAndNeverAutoApproves(): void
    {
        $input = self::fixture('SOLICITAR_BAJA');
        $valid = AssetRegisterLifecycle::project($input);
        self::assertTrue($valid['valid']);
        self::assertSame('BAJA_REQUIERE_REVISION', $valid['assets'][0]['state']);
        self::assertSame('fixture:evidence/signed-check', $valid['assets'][0]['evidence_ref']);
        self::assertSame('REQUIERE_REVISION', $valid['state']);
        self::assertFalse($valid['can_approve']);
        self::assertFalse($valid['can_close']);

        foreach ([null, '', 'https://example.test/document', 'user@example.test'] as $invalid) {
            $fixture = $input;
            $fixture['assets'][0]['evidence_ref'] = $invalid;
            self::assertFalse(AssetRegisterLifecycle::project($fixture)['valid']);
        }
        $fixture = $input;
        $fixture['assets'][0]['action'] = 'APROBADO';
        self::assertFalse(AssetRegisterLifecycle::project($fixture)['valid']);
        $fixture = $input;
        $fixture['assets'][0]['action'] = 'SOLICITAR_BAJA';
        $fixture['as_of'] = '2026-02-30';
        self::assertFalse(AssetRegisterLifecycle::project($fixture)['valid']);
    }

    public function testDomainDeniesUnauthorizedChangesAndInvalidEvidence(): void
    {
        foreach ([
            ['tenant-test-alpha', 'fixture:actor/a', false],
            ['tenant-test-alpha', 'actor@real.invalid', true],
            ['1099887766', 'fixture:actor/a', true],
        ] as [$tenant, $actor, $permission]) {
            try {
                new AssetRecord($tenant, 'fixture:asset/monitor', $actor, $permission);
                self::fail('Unauthorized asset record must be rejected.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
        $asset = new AssetRecord('tenant-test-alpha', 'fixture:asset/monitor', 'fixture:actor/a', true);
        foreach ([
            ['CERRADO', null],
            ['APROBADO', 'fixture:evidence/acta'],
            ['BAJA_REQUIERE_REVISION', null],
            ['BAJA_REQUIERE_REVISION', 'https://example.test/acta'],
        ] as [$state, $evidence]) {
            try {
                $asset->transition($state, 'fixture:actor/a', true, $evidence);
                self::fail('Unreviewed disposal/invalid evidence must be rejected.');
            } catch (DomainException) {
                self::assertSame(AssetRecord::PENDING, $asset->state());
            }
        }
        $asset->transition('BAJA_REQUIERE_REVISION', 'fixture:actor/a', true, 'fixture:evidence/acta');
        self::assertSame(AssetRecord::DECOMMISSION_REVIEW, $asset->state());
        self::assertSame('fixture:evidence/acta', $asset->snapshot()['evidence_ref']);
    }

    public function testActualApiServiceUsesCompanyScopeAndDeniesUnsafePayloads(): void
    {
        $rows = [];
        $audits = [];
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $db->method('insert')->willReturnCallback(
            static function (string $table, array $row) use (&$rows, &$audits): int {
                if ($table === 'condor_asset_audit') {
                    $audits[] = $row;
                } elseif ($table === 'condor_asset_record') {
                    $key = $row['tenant_ref'].'|'.$row['asset_ref'];
                    if (isset($rows[$key])) {
                        throw new DomainException('Duplicate synthetic asset.');
                    }
                    $rows[$key] = $row;
                } else {
                    throw new DomainException('Unexpected DB table.');
                }
                return 1;
            },
        );
        $db->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use (&$rows): array {
                self::assertStringContainsString('WHERE tenant_ref = ?', $sql);
                return array_values(array_filter($rows, static fn (array $row): bool =>
                    $row['tenant_ref'] === $params[0]));
            },
        );
        $db->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use (&$rows): array|false {
                self::assertStringContainsString('WHERE tenant_ref = ? AND asset_ref = ?', $sql);
                return $rows[$params[0].'|'.$params[1]] ?? false;
            },
        );
        $db->method('update')->willReturnCallback(
            static function (string $table, array $fields, array $keys) use (&$rows): int {
                self::assertSame('condor_asset_record', $table);
                $key = $keys['tenant_ref'].'|'.$keys['asset_ref'];
                if (!isset($rows[$key])) {
                    return 0;
                }
                $rows[$key] = array_replace($rows[$key], $fields);
                return 1;
            },
        );
        $service = new AssetApiService(new AssetRecordRepository($db));
        $a = new Tenant('Synthetic Alpha', 'fixture-alpha');
        $b = new Tenant('Synthetic Beta', 'fixture-beta');
        $officeA = new Branch($a, 'A', 'a', new LegalEntity($a, 'Alpha', null));
        $officeOtherCompany = new Branch($a, 'B', 'b', new LegalEntity($a, 'Beta', null));
        $officeB = new Branch($b, 'C', 'c', new LegalEntity($b, 'Gamma', null));

        $created = $service->createRecord($a, $officeA, ['asset_ref' => 'fixture:asset/demo']);
        self::assertSame('PENDIENTE', $created['state']);
        self::assertCount(1, $service->listRecords($a, $officeA));
        self::assertSame([], $service->listRecords($a, $officeOtherCompany));
        self::assertSame([], $service->listRecords($b, $officeB));
        self::assertNull($service->viewRecord($a, $officeOtherCompany, 'demo'));
        self::assertSame($created['asset_ref'], $service->viewRecord($a, $officeA, 'demo')['asset_ref']);

        foreach ([
            static fn () => $service->viewRecord($b, $officeA, 'demo'),
            static fn () => $service->createRecord($a, $officeA,
                ['asset_ref' => 'fixture:asset/other', 'responsible_ref' => 'fixture:responsible/demo']),
            static fn () => $service->changeState($a, $officeA, 'demo', ['state' => 'CERRADO']),
            static fn () => $service->changeState($a, $officeA, 'demo',
                ['state' => 'BAJA_REQUIERE_REVISION']),
            static fn () => $service->changeState($a, $officeA, 'demo',
                ['state' => 'BAJA_REQUIERE_REVISION', 'evidence_ref' => 'user@example.test']),
        ] as $denied) {
            try {
                $denied();
                self::fail('Cross-tenant/unknown-field/unsafe transition must be denied.');
            } catch (DomainException) {
                self::assertSame('PENDIENTE', $service->viewRecord($a, $officeA, 'demo')['state']);
            }
        }
        $updated = $service->changeState($a, $officeA, 'demo',
            ['state' => 'BAJA_REQUIERE_REVISION', 'evidence_ref' => 'fixture:evidence/acta']);
        self::assertSame('BAJA_REQUIERE_REVISION', $updated['state']);
        self::assertSame('fixture:evidence/acta', $updated['evidence_ref']);
        self::assertContains('synthetic.state_changed', array_column($audits, 'event'));
    }
}

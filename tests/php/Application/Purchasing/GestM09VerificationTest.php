<?php

declare(strict_types=1);

namespace App\Tests\Application\Purchasing;

use App\Application\Purchasing\PurchaseRequestPreview;
use App\Application\Purchasing\QuotationPreview;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** #1534 M09: exact negative assertions; all records are local synthetic fixtures. */
final class GestM09VerificationTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function quotation(): array
    {
        return [
            'tenant_ref' => 'fixture:tenant/current',
            'legal_entity_ref' => 'fixture:legal-entity/current',
            'site_ref' => 'fixture:site/current',
            'currency' => 'COP', 'mode' => 'preview_only',
            'lines' => [[
                'item_ref' => 'fixture:item/paper',
                'tenant_ref' => 'fixture:tenant/current',
                'legal_entity_ref' => 'fixture:legal-entity/current',
                'site_ref' => 'fixture:site/current',
                'quantity' => '2', 'unit_price_cop' => '125.50',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function request(): array
    {
        $quotation = self::quotation();
        unset($quotation['lines'][0]['unit_price_cop']);
        $quotation['lines'][0]['quantity'] = '1.250';
        unset($quotation['mode']);
        $quotation['mode'] = 'preview_only';
        $quotation['quotation'] = [
            'quotation_ref' => 'fixture:quotation/local',
            'tenant_ref' => 'fixture:tenant/current',
            'legal_entity_ref' => 'fixture:legal-entity/current',
            'site_ref' => 'fixture:site/current',
        ];
        return $quotation;
    }

    public function testS02QuotationIsSyntheticOnlyAndCannotPlaceOrders(): void
    {
        $result = QuotationPreview::preview(self::quotation());
        self::assertSame(251, $result['total_cop']);
        self::assertSame('SYNTHETIC_PREVIEW', $result['status']);
        self::assertFalse($result['persisted']);
        self::assertFalse($result['can_order']);
        self::assertFalse($result['can_pay']);
        self::assertFalse($result['can_approve']);
        self::assertFalse($result['can_dispatch']);
    }

    public function testS02RejectsForeignTenantAndUnexpectedFields(): void
    {
        $cases = [];
        $foreign = self::quotation();
        $foreign['lines'][0]['tenant_ref'] = 'fixture:tenant/foreign';
        $cases[] = $foreign;
        $unexpected = self::quotation();
        $unexpected['supplier_email'] = 'not-allowed@example.test';
        $cases[] = $unexpected;
        foreach ($cases as $payload) {
            try {
                QuotationPreview::preview($payload);
                self::fail('El alcance ajeno y los datos inesperados deben rechazarse.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testS03RequestIsPendingAndNeverPaysOrApproves(): void
    {
        $result = PurchaseRequestPreview::preview(self::request());
        self::assertSame('pending', $result['status']);
        self::assertSame('fixture:quotation/local', $result['quotation_ref']);
        self::assertSame('1.250', $result['lines'][0]['quantity']);
        self::assertFalse($result['persisted']);
        self::assertFalse($result['notified']);
        self::assertFalse($result['can_order']);
        self::assertFalse($result['can_approve']);
        self::assertFalse($result['can_pay']);
        self::assertFalse($result['can_dispatch']);
    }

    public function testS03RejectsForeignEntityDuplicateAndPersonalData(): void
    {
        $cases = [];
        $foreign = self::request();
        $foreign['quotation']['legal_entity_ref'] = 'fixture:legal-entity/foreign';
        $cases[] = $foreign;
        $duplicate = self::request();
        $duplicate['lines'][] = $duplicate['lines'][0];
        $cases[] = $duplicate;
        $personal = self::request();
        $personal['customer_email'] = 'invalid@example.test';
        $cases[] = $personal;
        $foreignSite = self::request();
        $foreignSite['lines'][0]['site_ref'] = 'fixture:site/foreign';
        $cases[] = $foreignSite;
        $missing = self::request();
        unset($missing['legal_entity_ref']);
        $cases[] = $missing;
        $badQuantity = self::request();
        $badQuantity['lines'][0]['quantity'] = '0';
        $cases[] = $badQuantity;
        foreach ($cases as $payload) {
            try {
                PurchaseRequestPreview::preview($payload);
                self::fail('La solicitud ficticia no debe admitir estas entradas.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testS01S04S05CannotCrossTenantOrMutateReceivedOrder(): void
    {
        $own = new Tenant('Empresa ficticia', 'm09-local');
        $other = new Tenant('Ajena ficticia', 'm09-other');
        $legal = new LegalEntity($own, 'Ficticia SAS', null);
        $supplier = new Supplier($own, 'SUP-1', 'Proveedor ficticio');
        $material = new Material($own, 'MAT-1', 'Papel', UnitOfMeasure::from('m'));
        $order = new PurchaseOrder($own, $legal, $supplier);
        $order->addLine($material, '2', '125');
        $order->markReceived('fixture:receipt-1', str_repeat('A', 26));
        $order->markReceived('fixture:receipt-1', str_repeat('A', 26));
        self::assertTrue($order->isReceived());
        self::assertCount(1, $order->lines());

        foreach ([
            static fn () => new PurchaseOrder($other, $legal, $supplier),
            static fn () => $order->addLine($material, '1', '1'),
            static fn () => $order->markReceived('fixture:receipt-2', str_repeat('A', 26)),
        ] as $operation) {
            try {
                $operation();
                self::fail('La orden no debe permitir tenant ajeno ni mutación posterior.');
            } catch (DomainException) {
                self::assertTrue($order->isReceived());
            }
        }
    }

    public function testPreviewHttpBoundaryRequiresPermissionsCsrfAndConstructionFlag(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/src/Http/Controller/PurchasingController.php');
        self::assertIsString($source);
        foreach (['Quotation', 'Request'] as $name) {
            $method = explode('public function preview'.$name, $source, 2)[1] ?? '';
            $method = explode('public function ', $method, 2)[0];
            $method = explode('private function ', $method, 2)[0];
            self::assertNotSame('', $method);
            self::assertStringContainsString('$this->requirePurchasingPreviewEnabled()', $method);
            self::assertStringContainsString('$this->requireCsrf($request)', $method);
            self::assertStringContainsString("authorizedBranchScope($branchId, 'inventory.create')", $method);
            self::assertStringNotContainsString('->persist(', $method);
        }
        self::assertStringContainsString('/purchasing/quotations/preview', $source);
        self::assertStringContainsString('/purchasing/requests/preview', $source);
        self::assertStringContainsString('CONDOR_PURCHASING_SYNTHETIC_API', $source);
        self::assertStringContainsString("getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'", $source);
        self::assertStringContainsString("'fixture:tenant/current'", $source);
        self::assertStringContainsString("'fixture:legal-entity/current'", $source);
        self::assertStringContainsString("'fixture:site/current'", $source);
    }
}

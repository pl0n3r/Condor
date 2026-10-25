<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commerce;

use App\Domain\Commerce\Entity\Customer;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CustomerDocumentTest extends TestCase
{
    public function testOptionalDocumentPreservesLegacyCustomerContract(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $customer = new Customer($tenant, 'Cliente', 'cliente@example.test');

        self::assertNull($customer->documentNumber());
        $customer->changeDocumentNumber('  cc-001.20  ');
        self::assertSame('CC-001.20', $customer->documentNumber());
        self::assertSame($tenant->id(), $customer->tenant()->id());
        self::assertSame('cliente@example.test', $customer->email());

        $customer->changeDocumentNumber(null);
        self::assertNull($customer->documentNumber());
    }

    public function testConstructorAcceptsDocumentWithoutChangingTenant(): void
    {
        $tenant = new Tenant('Local', 'local-'.bin2hex(random_bytes(4)));
        $other = new Tenant('Ajena', 'ajena-'.bin2hex(random_bytes(4)));
        $customer = new Customer($tenant, 'Cliente', documentNumber: 'nit 900.123-4');
        $foreign = new Customer($other, 'Cliente ajeno', documentNumber: 'CC 8765432');

        self::assertSame('NIT 900.123-4', $customer->documentNumber());
        self::assertSame('CC 8765432', $foreign->documentNumber());
        self::assertSame($tenant->id(), $customer->tenant()->id());
        self::assertNotSame($customer->tenant()->id(), $foreign->tenant()->id());
    }

    public function testRejectsMalformedOrOversizedDocument(): void
    {
        $tenant = new Tenant('Empresa', 'empresa-'.bin2hex(random_bytes(4)));
        $customer = new Customer($tenant, 'Cliente');

        foreach (["123\n4", '<1234>', str_repeat('9', 41)] as $invalid) {
            try {
                $customer->changeDocumentNumber($invalid);
                self::fail('Debía rechazar el documento inválido.');
            } catch (DomainException) {
                self::assertNull($customer->documentNumber());
            }
        }
    }
}

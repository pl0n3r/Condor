<?php

declare(strict_types=1);

namespace App\Tests\Domain\Organization;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BranchTest extends TestCase
{
    public function testItRejectsALegalEntityFromAnotherTenant(): void
    {
        $tenantA = new Tenant('Empresa A', 'empresa-a');
        $tenantB = new Tenant('Empresa B', 'empresa-b');
        $legalEntityB = new LegalEntity($tenantB, 'Empresa B SAS', '900000002');

        $this->expectException(DomainException::class);

        new Branch($tenantA, 'Principal', 'principal', $legalEntityB, true);
    }
}

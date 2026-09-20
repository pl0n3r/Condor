<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Tenancy;

use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use LogicException;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function testItCannotSwitchTenantInsideTheSameRequest(): void
    {
        $context = new TenantContext();
        $first = new Tenant('Empresa A', 'empresa-a');
        $second = new Tenant('Empresa B', 'empresa-b');

        $context->set($first);

        $this->expectException(LogicException::class);
        $context->set($second);
    }

    public function testResetAllowsANewRequestContext(): void
    {
        $context = new TenantContext();
        $first = new Tenant('Empresa A', 'empresa-a');
        $second = new Tenant('Empresa B', 'empresa-b');

        $context->set($first);
        $context->reset();
        $context->set($second);

        self::assertSame($second->id(), $context->require()->id());
    }
}

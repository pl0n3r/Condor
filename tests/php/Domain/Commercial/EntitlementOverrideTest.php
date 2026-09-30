<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commercial;

use App\Domain\Commercial\EntitlementOverride;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class EntitlementOverrideTest extends TestCase
{
    public function testOverridesAreTenantScopedAuditableAndDoNotMutateCatalog(): void
    {
        $override = new EntitlementOverride(
            'tenant-a',
            'limit',
            'users',
            15,
            'Contrato comercial especial',
            'owner',
            new DateTimeImmutable('2026-09-30T10:00:00Z'),
        );

        self::assertSame('tenant-a', $override->tenantId());
        self::assertSame('limit', $override->entitlementNamespace());
        self::assertSame(15, $override->value());
        self::assertSame(
            [
                'namespace' => 'limit',
                'key' => 'users',
                'value' => 15,
                'reason' => 'Contrato comercial especial',
                'actor' => 'owner',
                'created_at' => '2026-09-30T10:00:00+00:00',
            ],
            $override->snapshot(),
        );
    }

    /** @dataProvider baseControlProvider */
    public function testBaseControlsCannotBecomeCommercialOverrides(string $key): void
    {
        $this->expectException(DomainException::class);
        new EntitlementOverride(
            'tenant-a',
            'capability',
            $key,
            false,
            'No permitido',
            'owner',
            new DateTimeImmutable('2026-09-30T10:00:00Z'),
        );
    }

    /** @return iterable<string,array{string}> */
    public static function baseControlProvider(): iterable
    {
        foreach (
            ['security', 'privacy', 'backup', 'recovery', 'integrity'] as $key
        ) {
            yield $key => [$key];
        }
    }
}

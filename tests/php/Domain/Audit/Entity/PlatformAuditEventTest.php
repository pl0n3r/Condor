<?php

declare(strict_types=1);

namespace App\Tests\Domain\Audit\Entity;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PlatformAuditEventTest extends TestCase
{
    public function testKeepsActorTenantMetadataAndNestedContext(): void
    {
        $actor = '01J8A000000000000000000001';
        $tenant = '01J8A000000000000000000002';
        $context = ['permission' => 'editor', 'flags' => ['approved' => true, 'retry' => false]];

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $event = new PlatformAuditEvent($actor, $tenant, 'tenant.admin.updated', 'Tenant', $tenant, $context);
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/D', self::value($event, 'id'));
        self::assertSame($actor, self::value($event, 'actorUserId'));
        self::assertSame($tenant, self::value($event, 'tenantId'));
        self::assertSame('tenant.admin.updated', self::value($event, 'action'));
        self::assertSame('Tenant', self::value($event, 'entityType'));
        self::assertSame($tenant, self::value($event, 'entityId'));
        self::assertSame($context, self::value($event, 'context'));

        $createdAt = self::value($event, 'createdAt');
        self::assertInstanceOf(DateTimeImmutable::class, $createdAt);
        self::assertSame('UTC', $createdAt->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $createdAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $createdAt->getTimestamp());
    }

    public function testSupportsGlobalEventWithNullTenantAndDefaultEmptyContext(): void
    {
        $first = new PlatformAuditEvent('actor-1', null, 'platform.maintenance', 'System', 'global');
        $second = new PlatformAuditEvent('actor-1', null, 'platform.maintenance', 'System', 'global');

        self::assertNull(self::value($first, 'tenantId'));
        self::assertSame([], self::value($first, 'context'));
        self::assertSame('actor-1', self::value($first, 'actorUserId'));
        self::assertNotSame(self::value($first, 'id'), self::value($second, 'id'));
    }

    private static function value(PlatformAuditEvent $event, string $name): mixed
    {
        return (new ReflectionProperty(PlatformAuditEvent::class, $name))->getValue($event);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Domain\Audit\Entity;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AuditEventTest extends TestCase
{
    public function testPersistsTenantActorActionEntityAndContextWithoutMutation(): void
    {
        $tenant = new Tenant('Audit tenant', 'audit-tenant');
        $context = ['before' => ['status' => 'draft'], 'after' => ['status' => 'approved'], 'count' => 0];

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $event = new AuditEvent($tenant, '01J8A000000000000000000001', 'record.approved', 'Record', 'entity-123', $context);
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/D', self::value($event, 'id'));
        self::assertSame($tenant, self::value($event, 'tenant'));
        self::assertSame('01J8A000000000000000000001', self::value($event, 'actorUserId'));
        self::assertSame('record.approved', self::value($event, 'action'));
        self::assertSame('Record', self::value($event, 'entityType'));
        self::assertSame('entity-123', self::value($event, 'entityId'));
        self::assertSame($context, self::value($event, 'context'));
        self::assertSame(['before' => ['status' => 'draft'], 'after' => ['status' => 'approved'], 'count' => 0], $context);

        $createdAt = self::value($event, 'createdAt');
        self::assertInstanceOf(DateTimeImmutable::class, $createdAt);
        self::assertSame('UTC', $createdAt->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $createdAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $createdAt->getTimestamp());
    }

    public function testAllowsSystemActorAndEmptyContextAndGeneratesNewIdentity(): void
    {
        $tenant = new Tenant('System', 'system');
        $first = new AuditEvent($tenant, null, 'sync.started', 'Job', 'job-1');
        $second = new AuditEvent($tenant, null, 'sync.started', 'Job', 'job-1');

        self::assertNull(self::value($first, 'actorUserId'));
        self::assertSame([], self::value($first, 'context'));
        self::assertSame($tenant, self::value($first, 'tenant'));
        self::assertNotSame(self::value($first, 'id'), self::value($second, 'id'));
    }

    private static function value(AuditEvent $event, string $name): mixed
    {
        return (new ReflectionProperty(AuditEvent::class, $name))->getValue($event);
    }
}

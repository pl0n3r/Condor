<?php

declare(strict_types=1);

namespace App\Tests\Application\SecurityReview;

use App\Application\SecurityReview\SyntheticReviewIncidentStore;
use App\Domain\SecurityReview\ReviewState;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SyntheticReviewIncidentStoreTest extends TestCase
{
    public function testTenantAndBranchIsolationAndAudit(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $store = new SyntheticReviewIncidentStore();

        $review = $store->create($session, 'tenant-a', 'branch-1', 'actor-a', ReviewState::RECERTIFICATION);
        $incident = $store->create($session, 'tenant-a', 'branch-1', 'actor-b', ReviewState::INCIDENT);
        self::assertSame('pending', $review['status']);
        self::assertSame('open', $incident['status']);
        self::assertStringStartsWith('fixture:', $review['id']);
        self::assertCount(2, $store->items($session, 'tenant-a', 'branch-1'));
        self::assertSame([], $store->items($session, 'tenant-b', 'branch-1'));
        self::assertSame([], $store->items($session, 'tenant-a', 'branch-2'));
        self::assertSame([], $store->audit($session, 'tenant-b', 'branch-1'));

        $updated = $store->transition($session, 'tenant-a', 'branch-1', 'actor-a', $review['id'], 'approved');
        self::assertSame('approved', $updated['status']);
        self::assertCount(3, $store->audit($session, 'tenant-a', 'branch-1'));
        self::assertSame('transitioned', $store->audit($session, 'tenant-a', 'branch-1')[2]['event']);
        self::assertStringNotContainsString('actor-a', json_encode($store->audit($session, 'tenant-a', 'branch-1'), JSON_THROW_ON_ERROR));
    }

    public function testForeignIdsAndInvalidTransitionsAreRejected(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $store = new SyntheticReviewIncidentStore();
        $item = $store->create($session, 'tenant-a', 'branch-1', 'actor', ReviewState::INCIDENT);

        try {
            $store->transition($session, 'tenant-b', 'branch-1', 'actor', $item['id'], 'investigating');
            self::fail('A foreign fixture must not be accessible.');
        } catch (DomainException $exception) {
            self::assertSame('Simulación no encontrada en el ámbito solicitado.', $exception->getMessage());
        }

        try {
            $store->transition($session, 'tenant-a', 'branch-1', 'actor', $item['id'], 'resolved');
            self::fail('Skipping investigating must be denied.');
        } catch (DomainException $exception) {
            self::assertSame('Transición de estado no permitida.', $exception->getMessage());
        }

        self::assertSame('open', $store->items($session, 'tenant-a', 'branch-1')[0]['status']);
        self::assertCount(1, $store->audit($session, 'tenant-a', 'branch-1'));
        $this->expectException(DomainException::class);
        $store->create($session, 'tenant-a', 'branch-1', 'actor', 'live-incident');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\BillingAdapter;
use App\Application\Commercial\BillingIdempotencyRecord;
use App\Application\Commercial\BillingIdempotencyStore;
use App\Application\Commercial\BillingRequest;
use App\Application\Commercial\BillingResult;
use App\Application\Commercial\BillingSubmissionCoordinator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BillingSubmissionCoordinatorTest extends TestCase
{
    public function testSameRequestIsSubmittedOnlyOnce(): void
    {
        $adapter = new class implements BillingAdapter {
            public int $submits = 0;

            public function submit(BillingRequest $request): BillingResult
            {
                ++$this->submits;

                return new BillingResult(
                    'accepted',
                    'processor:synthetic-001',
                    'billing:evidence:accepted-001',
                );
            }
        };

        $store = new class implements BillingIdempotencyStore {
            /** @var array<string,BillingIdempotencyRecord> */
            private array $records = [];

            public function find(string $idempotencyKey): ?BillingIdempotencyRecord
            {
                return $this->records[$idempotencyKey] ?? null;
            }

            public function save(BillingIdempotencyRecord $record): void
            {
                $this->records[$record->idempotencyKey()] = $record;
            }
        };

        $request = self::request();
        $coordinator = new BillingSubmissionCoordinator($adapter, $store);

        $first = $coordinator->submit($request);
        $second = $coordinator->submit(BillingRequest::fromArray($request->toArray()));

        self::assertSame(1, $adapter->submits);
        self::assertSame($first->toArray(), $second->toArray());
    }

    public function testConflictAndUnknownRetryFailClosedWithoutSecondSubmit(): void
    {
        $adapter = new class implements BillingAdapter {
            public int $submits = 0;

            public function submit(BillingRequest $request): BillingResult
            {
                ++$this->submits;

                return new BillingResult(
                    'unknown',
                    null,
                    'billing:evidence:unknown-001',
                );
            }
        };

        $store = new class implements BillingIdempotencyStore {
            /** @var array<string,BillingIdempotencyRecord> */
            private array $records = [];

            public function find(string $idempotencyKey): ?BillingIdempotencyRecord
            {
                return $this->records[$idempotencyKey] ?? null;
            }

            public function save(BillingIdempotencyRecord $record): void
            {
                $this->records[$record->idempotencyKey()] = $record;
            }
        };

        $request = self::request();
        $coordinator = new BillingSubmissionCoordinator($adapter, $store);

        self::assertSame('unknown', $coordinator->submit($request)->outcome());

        try {
            $coordinator->submit($request);
            self::fail('Unknown retry was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('billing_reconciliation_required', $exception->getMessage());
        }

        $changed = BillingRequest::fromArray([
            ...$request->toArray(),
            'amount_minor' => 299900,
        ]);

        try {
            $coordinator->submit($changed);
            self::fail('Conflicting idempotency key was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('billing_idempotency_conflict', $exception->getMessage());
        }

        self::assertSame(1, $adapter->submits);
    }

    private static function request(): BillingRequest
    {
        return BillingRequest::fromArray([
            'tenant_ref' => 'tenant:synthetic-a',
            'subscription_ref' => 'subscription:01hzzzzzzzzzzzzzzzzzzzzzzz',
            'quote_ref' => 'quote:01hyyyyyyyyyyyyyyyyyyyyyyy',
            'amount_minor' => 199900,
            'currency' => 'COP',
            'idempotency_key' => 'billing:tenant-a:2026-10',
        ]);
    }
}

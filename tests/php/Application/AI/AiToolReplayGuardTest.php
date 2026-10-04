<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolReplayKey;
use App\Application\AI\AiToolRequest;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolReplayGuardTest extends TestCase
{
    public function testFirstClaimSucceedsAndDuplicateIsRejectedLocally(): void
    {
        $guard = new AiToolReplayGuard();
        $first = $this->key('request:draft-001');
        $second = $this->key('request:draft-002');

        self::assertTrue($guard->claim($first));
        self::assertFalse($guard->claim($first));
        self::assertTrue($guard->claim($second));
        self::assertFalse($guard->claim($second));
    }

    public function testGuardIsBoundedAndFailsClosedWithoutEvictingClaims(): void
    {
        $guard = new AiToolReplayGuard();
        $first = null;

        for ($index = 0; $index < 128; ++$index) {
            $key = $this->key(sprintf('request:claim-%03d', $index));
            $first ??= $key;
            self::assertTrue($guard->claim($key));
        }

        self::assertInstanceOf(AiToolReplayKey::class, $first);

        try {
            $guard->claim($this->key('request:claim-128'));
            self::fail('La claim 129 debe fallar cerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        self::assertFalse($guard->claim($first));
    }

    private function key(string $requestRef): AiToolReplayKey
    {
        $policy = new AiToolPolicy();
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'content.draft.update',
            'knowledge_refs' => [],
        ], $policy);
        $request = AiToolRequest::fromArray(
            $context,
            $policy,
            [
                'tenant_id' => 'tenant-a',
                'tool' => 'content.draft.update',
                'request_ref' => $requestRef,
            ],
        );

        return AiToolReplayKey::fromRequest($request, $context, $policy);
    }
}

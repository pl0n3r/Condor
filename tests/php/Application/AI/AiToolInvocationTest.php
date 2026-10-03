<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolInvocation;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolInvocationTest extends TestCase
{
    public function testAuthorizedToolExecutesAndEmitsMinimalAudit(): void
    {
        $policy = new AiToolPolicy();
        $context = self::context($policy, 'tenant-a', 'catalog.read');
        $executions = 0;

        $result = AiToolInvocation::invoke(
            $context,
            $policy,
            'tenant-a',
            'catalog.read',
            'evidence:catalog-check',
            '2026-10-03T10:07:00+00:00',
            static function () use (&$executions): array {
                ++$executions;

                return ['raw' => 'must-not-leak'];
            },
        );

        self::assertSame(1, $executions);
        self::assertSame('success', $result['outcome']);
        self::assertTrue($result['executed']);
        self::assertSame('evidence:catalog-check', $result['evidence_ref']);
        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'catalog.read',
                'outcome' => 'success',
                'evidence_ref' => 'evidence:catalog-check',
                'timestamp' => '2026-10-03T10:07:00+00:00',
            ],
            $result['audit'],
        );
        self::assertArrayNotHasKey('raw', $result);
    }

    public function testSensitiveUnknownAndCrossTenantNeverExecute(): void
    {
        $policy = new AiToolPolicy();
        $executions = 0;
        $executor = static function () use (&$executions): void {
            ++$executions;
        };

        $cases = [
            [
                self::context($policy, 'tenant-a', 'identity.permission.change'),
                'tenant-a',
                'identity.permission.change',
            ],
            [
                self::context($policy, 'tenant-a', 'catalog.read'),
                'tenant-a',
                'unknown.read',
            ],
            [
                self::context($policy, 'tenant-a', 'catalog.read'),
                'tenant-b',
                'catalog.read',
            ],
        ];

        foreach ($cases as [$context, $tenantId, $tool]) {
            $result = AiToolInvocation::invoke(
                $context,
                $policy,
                $tenantId,
                $tool,
                'evidence:denied-check',
                '2026-10-03T10:07:00+00:00',
                $executor,
            );

            self::assertSame('denied', $result['outcome']);
            self::assertFalse($result['executed']);
            self::assertSame('denied', $result['audit']['outcome']);
        }

        self::assertSame(0, $executions);
    }

    public function testInvalidAuditMetadataFailsBeforeExecutor(): void
    {
        $policy = new AiToolPolicy();
        $context = self::context($policy, 'tenant-a', 'catalog.read');

        foreach (
            [
                ['invalid-evidence-ref', '2026-10-03T10:07:00+00:00'],
                ['evidence:catalog-check', 'not-a-timestamp'],
            ] as [$evidenceRef, $timestamp]
        ) {
            $executions = 0;

            try {
                AiToolInvocation::invoke(
                    $context,
                    $policy,
                    'tenant-a',
                    'catalog.read',
                    $evidenceRef,
                    $timestamp,
                    static function () use (&$executions): void {
                        ++$executions;
                    },
                );

                self::fail('Invalid audit metadata must fail closed.');
            } catch (DomainException) {
                self::assertSame(0, $executions);
            }
        }
    }

    private static function context(
        AiToolPolicy $policy,
        string $tenantId,
        string $tool,
    ): AiTenantContext {
        return AiTenantContext::fromArray(
            [
                'tenant_id' => $tenantId,
                'tool' => $tool,
                'knowledge_refs' => [],
            ],
            $policy,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiConversationCore;
use App\Application\AI\AiToolReplayGuard;
use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AiConversationCoreTest extends TestCase
{
    public function testTurnRoutesKnowledgeAndAuthorizedToolsThroughSingleTenantContext(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-03T12:00:00Z');

        $knowledge = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'knowledge.read',
                'knowledge_refs' => ['knowledge:guide'],
            ], $policy),
            $policy,
            [
                'intent' => 'knowledge',
                'tenant_id' => 'tenant-a',
                'knowledge_request' => $this->knowledgeRequest(),
            ],
            [$this->article()],
            $at,
            AiToolRegistry::fromArray($policy, []),
        );

        self::assertSame('ready', $knowledge['status']);
        self::assertSame('knowledge', $knowledge['route']);
        self::assertFalse($knowledge['executed']);
        self::assertSame(['knowledge:guide'], $knowledge['evidence_refs']);
        self::assertArrayNotHasKey('answer', $knowledge);
        self::assertArrayNotHasKey('body', $knowledge);

        $executions = 0;
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
            ],
        );
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => static function (array $inputs) use (&$executions): array {
                    ++$executions;

                    return ['raw' => 'must-not-leak'];
                },
            ]],
        );

        $tool = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'inputs' => [],
                'request_ref' => 'request:catalog-turn',
                'evidence_ref' => 'evidence:catalog-turn',
                'timestamp' => '2026-10-03T12:00:00+00:00',
            ],
            [],
            $at,
            $registry,
        );

        self::assertSame(1, $executions);
        self::assertSame('completed', $tool['status']);
        self::assertSame('tool', $tool['route']);
        self::assertSame(['evidence:catalog-turn'], $tool['evidence_refs']);
        self::assertSame('tenant:tenant-a', $tool['audit']['tenant_ref']);
        self::assertSame('catalog.read', $tool['audit']['tool_ref']);
        self::assertSame('request:catalog-turn', $tool['receipt']['request_ref']);
        self::assertSame('authorized', $tool['receipt']['decision']);
        self::assertSame('read_only', $tool['receipt']['risk']);
        self::assertSame('success', $tool['receipt']['outcome']);
        self::assertSame('evidence:catalog-turn', $tool['receipt']['evidence_ref']);
        self::assertArrayNotHasKey('raw', $tool);
        self::assertArrayNotHasKey('tool_result', $tool);
        self::assertArrayNotHasKey('raw_output', $tool['receipt']);
    }

    public function testToolTurnReturnsOnlyDescriptorValidatedResult(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
                'output_names' => ['label', 'product_ref'],
                'required_outputs' => ['product_ref'],
            ],
        );
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => static fn (array $inputs): array => [
                    'product_ref' => 'product:42',
                    'label' => 'Industrial',
                ],
            ]],
        );

        $result = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'inputs' => [],
                'request_ref' => 'request:validated-result',
                'evidence_ref' => 'evidence:validated-result',
                'timestamp' => '2026-10-04T10:00:00+00:00',
            ],
            [],
            $at,
            $registry,
        );

        self::assertSame('completed', $result['status']);
        self::assertTrue($result['executed']);
        self::assertSame(
            [
                'product_ref' => 'product:42',
                'label' => 'Industrial',
            ],
            $result['tool_result'],
        );
        self::assertSame('success', $result['receipt']['outcome']);
        self::assertArrayNotHasKey('tool_result', $result['receipt']);
        self::assertArrayNotHasKey('product_ref', $result['receipt']);
        self::assertArrayNotHasKey('label', $result['receipt']);
        self::assertArrayNotHasKey('product_ref', $result['audit']);
        self::assertArrayNotHasKey('label', $result['audit']);
    }

    public function testInvalidHandlerOutputHandoffsWithoutRawLeak(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
                'output_names' => ['product_ref'],
                'required_outputs' => ['product_ref'],
            ],
        );
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => static fn (array $inputs): array => [
                    'product_ref' => ['raw' => 'opaque'],
                ],
            ]],
        );

        $result = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'inputs' => [],
                'request_ref' => 'request:invalid-result',
                'evidence_ref' => 'evidence:invalid-result',
                'timestamp' => '2026-10-04T10:00:00+00:00',
            ],
            [],
            $at,
            $registry,
        );

        self::assertSame('handoff', $result['status']);
        self::assertSame('tool_failed', $result['reason']);
        self::assertTrue($result['executed']);
        self::assertSame('failure', $result['receipt']['outcome']);
        self::assertArrayNotHasKey('tool_result', $result);
        self::assertArrayNotHasKey('tool_result', $result['receipt']);

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('opaque', $serialized);
        self::assertStringNotContainsString('"raw"', $serialized);
    }

    public function testSensitiveUnknownOrEvidenceGapHandoffsWithoutSideEffects(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-03T12:00:00Z');
        $executions = 0;
        $executor = static function (array $inputs) use (&$executions): void {
            ++$executions;
        };
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
            ],
        );
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => $executor,
            ]],
        );

        $sensitive = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'identity.permission.change',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-a',
                'tool' => 'identity.permission.change',
                'inputs' => [],
                'request_ref' => 'request:sensitive-turn',
                'evidence_ref' => 'evidence:sensitive-turn',
                'timestamp' => '2026-10-03T12:00:00+00:00',
            ],
            [],
            $at,
            $registry,
        );
        self::assertSame('handoff', $sensitive['status']);
        self::assertSame('tool_sensitive_requires_human', $sensitive['reason']);
        self::assertFalse($sensitive['executed']);
        self::assertSame('tenant:tenant-a', $sensitive['handoff']['tenant_ref']);
        self::assertSame('tool', $sensitive['handoff']['route']);
        self::assertSame([], $sensitive['handoff']['evidence_refs']);
        self::assertSame(
            [
                'tenant_ref' => 'tenant:tenant-a',
                'tool_ref' => 'identity.permission.change',
                'request_ref' => 'request:sensitive-turn',
                'evidence_ref' => 'evidence:sensitive-turn',
            ],
            $sensitive['sensitive_request'],
        );
        self::assertArrayNotHasKey('permission_id', $sensitive['sensitive_request']);
        self::assertArrayNotHasKey('actor_id', $sensitive['sensitive_request']);

        $invalidRequest = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'inputs' => [],
                'request_ref' => 'customer@example.test',
                'evidence_ref' => 'evidence:invalid-request',
                'timestamp' => '2026-10-03T12:00:00+00:00',
            ],
            [],
            $at,
            $registry,
        );
        self::assertSame('denied', $invalidRequest['status']);
        self::assertSame('tool_request_denied', $invalidRequest['reason']);
        self::assertFalse($invalidRequest['executed']);

        $unknown = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            ['intent' => 'unknown', 'tenant_id' => 'tenant-a'],
            [],
            $at,
            $registry,
        );
        self::assertSame('handoff', $unknown['status']);
        self::assertSame('intent_not_supported', $unknown['reason']);
        self::assertSame('tenant:tenant-a', $unknown['handoff']['tenant_ref']);
        self::assertSame('none', $unknown['handoff']['route']);

        $crossTenant = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'catalog.read',
                'knowledge_refs' => [],
            ], $policy),
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-b',
                'tool' => 'catalog.read',
                'inputs' => [],
                'request_ref' => 'request:cross-tenant',
                'evidence_ref' => 'evidence:cross-tenant',
                'timestamp' => '2026-10-03T12:00:00+00:00',
            ],
            [],
            $at,
            $registry,
        );
        self::assertSame('handoff', $crossTenant['status']);
        self::assertSame('tenant_context_mismatch', $crossTenant['reason']);
        self::assertSame('tenant:tenant-a', $crossTenant['handoff']['tenant_ref']);
        self::assertSame('none', $crossTenant['handoff']['route']);

        $gap = AiConversationCore::turn(
            AiTenantContext::fromArray([
                'tenant_id' => 'tenant-a',
                'tool' => 'knowledge.read',
                'knowledge_refs' => ['knowledge:guide'],
            ], $policy),
            $policy,
            [
                'intent' => 'knowledge',
                'tenant_id' => 'tenant-a',
                'knowledge_request' => $this->knowledgeRequest(['minimum_sources' => 2]),
            ],
            [$this->article()],
            $at,
            $registry,
        );
        self::assertSame('handoff', $gap['status']);
        self::assertSame('evidence_insufficient', $gap['reason']);
        self::assertSame('knowledge', $gap['handoff']['route']);
        self::assertSame($gap['evidence_refs'], $gap['handoff']['evidence_refs']);

        self::assertSame(0, $executions);
        foreach ([$sensitive, $invalidRequest, $unknown, $crossTenant, $gap] as $result) {
            self::assertArrayNotHasKey('answer', $result);
            self::assertArrayNotHasKey('prompt', $result);
            self::assertArrayNotHasKey('body', $result);
        }
    }

    public function testReversibleWriteRequiresGuardAndDuplicateNeverReexecutesHandler(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $context = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'content.draft.update',
            'knowledge_refs' => [],
        ], $policy);
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'content.draft.update',
                'risk' => AiToolPolicy::REVERSIBLE_WRITE,
                'input_names' => [],
                'required_inputs' => [],
            ],
        );
        $executions = 0;
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'content.draft.update',
                'descriptor' => $descriptor,
                'handler' => static function (array $inputs) use (&$executions): array {
                    ++$executions;

                    return [];
                },
            ]],
        );
        $turn = [
            'intent' => 'tool',
            'tenant_id' => 'tenant-a',
            'tool' => 'content.draft.update',
            'inputs' => [],
            'request_ref' => 'request:reversible-write-001',
            'evidence_ref' => 'evidence:reversible-write-001',
            'timestamp' => '2026-10-04T12:00:00+00:00',
        ];

        $withoutGuard = AiConversationCore::turn(
            $context,
            $policy,
            $turn,
            [],
            $at,
            $registry,
        );
        self::assertSame('handoff', $withoutGuard['status']);
        self::assertSame('tool_replay_guard_required', $withoutGuard['reason']);
        self::assertFalse($withoutGuard['executed']);
        self::assertSame(0, $executions);

        $guard = new AiToolReplayGuard();
        $first = AiConversationCore::turn(
            $context,
            $policy,
            $turn,
            [],
            $at,
            $registry,
            $guard,
        );
        self::assertSame('completed', $first['status']);
        self::assertTrue($first['executed']);
        self::assertSame('reversible_write', $first['receipt']['risk']);
        self::assertSame(1, $executions);

        $duplicate = AiConversationCore::turn(
            $context,
            $policy,
            $turn,
            [],
            $at,
            $registry,
            $guard,
        );
        self::assertSame('handoff', $duplicate['status']);
        self::assertSame('tool_replay_detected', $duplicate['reason']);
        self::assertFalse($duplicate['executed']);
        self::assertSame(1, $executions);
        self::assertArrayNotHasKey('receipt', $duplicate);

        $serialized = json_encode([$first, $duplicate], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('replay:', $serialized);
        self::assertStringNotContainsString('claims', $serialized);
    }

    public function testReadOnlyAndSensitivePathsPreserveExistingSemanticsWithGuard(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-04T12:00:00Z');
        $guard = new AiToolReplayGuard();
        $executions = 0;
        $readDescriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
            ],
        );
        $readRegistry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $readDescriptor,
                'handler' => static function (array $inputs) use (&$executions): array {
                    ++$executions;

                    return [];
                },
            ]],
        );
        $readContext = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'knowledge_refs' => [],
        ], $policy);
        $readTurn = [
            'intent' => 'tool',
            'tenant_id' => 'tenant-a',
            'tool' => 'catalog.read',
            'inputs' => [],
            'request_ref' => 'request:read-repeat',
            'evidence_ref' => 'evidence:read-repeat',
            'timestamp' => '2026-10-04T12:00:00+00:00',
        ];

        $first = AiConversationCore::turn(
            $readContext,
            $policy,
            $readTurn,
            [],
            $at,
            $readRegistry,
            $guard,
        );
        $second = AiConversationCore::turn(
            $readContext,
            $policy,
            $readTurn,
            [],
            $at,
            $readRegistry,
            $guard,
        );
        self::assertSame('completed', $first['status']);
        self::assertSame('completed', $second['status']);
        self::assertSame(2, $executions);

        $sensitiveContext = AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => 'identity.permission.change',
            'knowledge_refs' => [],
        ], $policy);
        $sensitive = AiConversationCore::turn(
            $sensitiveContext,
            $policy,
            [
                'intent' => 'tool',
                'tenant_id' => 'tenant-a',
                'tool' => 'identity.permission.change',
                'inputs' => [],
                'request_ref' => 'request:sensitive-replay-bridge',
                'evidence_ref' => 'evidence:sensitive-replay-bridge',
                'timestamp' => '2026-10-04T12:00:00+00:00',
            ],
            [],
            $at,
            AiToolRegistry::fromArray($policy, []),
            $guard,
        );
        self::assertSame('handoff', $sensitive['status']);
        self::assertSame('tool_sensitive_requires_human', $sensitive['reason']);
        self::assertFalse($sensitive['executed']);
    }

    private function article(): KnowledgeArticle
    {
        return KnowledgeArticle::fromArray([
            'id' => 'guide',
            'version' => 1,
            'state' => 'published',
            'visibility' => 'customer',
            'audience' => 'customer',
            'locale' => 'es-CO',
            'scope' => 'tenant:tenant-a',
            'title' => 'Guía autorizada',
            'body' => 'Contenido interno que no se devuelve como respuesta generada.',
            'owner_ref' => 'team:support',
            'source_ref' => 'spec:guide',
            'tags' => ['support'],
            'modules' => ['admin'],
            'product_version_refs' => ['plan-version:negocio@2'],
            'capability_refs' => ['capability:knowledge'],
            'reviewed_at' => '2026-09-01T00:00:00Z',
            'stale_after' => '2026-11-01T00:00:00Z',
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function knowledgeRequest(array $overrides = []): array
    {
        return array_replace([
            'visibility' => 'customer',
            'locale' => 'es-CO',
            'module' => 'admin',
            'evidence_confidence' => 'sufficient',
            'minimum_sources' => 1,
        ], $overrides);
    }
}

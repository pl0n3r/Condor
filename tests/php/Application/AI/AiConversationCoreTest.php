<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiConversationCore;
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
                'handler' => static function () use (&$executions): array {
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
        self::assertArrayNotHasKey('raw_output', $tool['receipt']);
    }

    public function testSensitiveUnknownOrEvidenceGapHandoffsWithoutSideEffects(): void
    {
        $policy = new AiToolPolicy();
        $at = new DateTimeImmutable('2026-10-03T12:00:00Z');
        $executions = 0;
        $executor = static function () use (&$executions): void {
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

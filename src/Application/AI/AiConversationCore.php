<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;
use DomainException;

final class AiConversationCore
{
    private const KNOWLEDGE_KEYS = ['intent', 'knowledge_request', 'tenant_id'];
    private const TOOL_KEYS = ['evidence_ref', 'intent', 'tenant_id', 'timestamp', 'tool'];

    /**
     * @param array<string, mixed> $turn
     * @param list<KnowledgeArticle> $articles
     * @param callable():mixed $toolExecutor
     * @return array{
     *     status:'ready'|'completed'|'denied'|'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:bool,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:array<string, string>|null
     * }
     */
    public static function turn(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $turn,
        array $articles,
        DateTimeImmutable $at,
        callable $toolExecutor,
    ): array {
        $intent = $turn['intent'] ?? null;
        $tenantId = $turn['tenant_id'] ?? null;

        if (!is_string($intent) || !is_string($tenantId)) {
            return self::handoff('turn_not_canonical');
        }

        $intent = strtolower(trim($intent));
        $tenantId = trim($tenantId);
        if ($tenantId !== $context->tenantId()) {
            return self::handoff('tenant_context_mismatch');
        }

        return match ($intent) {
            'knowledge' => self::knowledgeTurn($context, $policy, $turn, $articles, $at),
            'tool' => self::toolTurn($context, $policy, $turn, $toolExecutor),
            default => self::handoff('intent_not_supported'),
        };
    }

    /**
     * @param array<string, mixed> $turn
     * @param list<KnowledgeArticle> $articles
     * @return array{
     *     status:'ready'|'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:false,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:null
     * }
     */
    private static function knowledgeTurn(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $turn,
        array $articles,
        DateTimeImmutable $at,
    ): array {
        if (!self::hasExactKeys($turn, self::KNOWLEDGE_KEYS)
            || !is_array($turn['knowledge_request'])) {
            return self::handoff('turn_not_canonical', 'knowledge');
        }

        try {
            $result = AiKnowledgeGateway::retrieve(
                $context,
                $policy,
                $articles,
                $turn['knowledge_request'],
                $at,
            );
        } catch (DomainException) {
            return self::handoff('knowledge_request_invalid', 'knowledge');
        }

        if ($result['status'] !== 'ready') {
            return [
                'status' => 'handoff',
                'route' => 'knowledge',
                'reason' => $result['reason'],
                'executed' => false,
                'evidence_refs' => $result['evidence_refs'],
                'sources' => $result['sources'],
                'audit' => null,
            ];
        }

        return [
            'status' => 'ready',
            'route' => 'knowledge',
            'reason' => 'evidence_sufficient',
            'executed' => false,
            'evidence_refs' => $result['evidence_refs'],
            'sources' => $result['sources'],
            'audit' => null,
        ];
    }

    /**
     * @param array<string, mixed> $turn
     * @param callable():mixed $toolExecutor
     * @return array{
     *     status:'completed'|'denied'|'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:bool,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:array<string, string>|null
     * }
     */
    private static function toolTurn(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $turn,
        callable $toolExecutor,
    ): array {
        if (!self::hasExactKeys($turn, self::TOOL_KEYS)
            || !is_string($turn['tool'])
            || !is_string($turn['evidence_ref'])
            || !is_string($turn['timestamp'])) {
            return self::handoff('turn_not_canonical', 'tool');
        }

        try {
            $result = AiToolInvocation::invoke(
                $context,
                $policy,
                $context->tenantId(),
                $turn['tool'],
                $turn['evidence_ref'],
                $turn['timestamp'],
                $toolExecutor,
            );
        } catch (DomainException) {
            return self::handoff('tool_request_invalid', 'tool');
        }

        $status = match ($result['outcome']) {
            'success' => 'completed',
            'denied' => 'denied',
            default => 'handoff',
        };
        $reason = match ($result['outcome']) {
            'success' => 'tool_succeeded',
            'denied' => 'tool_denied',
            default => 'tool_failed',
        };

        return [
            'status' => $status,
            'route' => 'tool',
            'reason' => $reason,
            'executed' => $result['executed'],
            'evidence_refs' => [$result['evidence_ref']],
            'sources' => [],
            'audit' => $result['audit'],
        ];
    }

    /**
     * @param array<string, mixed> $turn
     * @param list<string> $expected
     */
    private static function hasExactKeys(array $turn, array $expected): bool
    {
        $keys = array_keys($turn);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    /**
     * @param 'knowledge'|'tool'|'none' $route
     * @return array{
     *     status:'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:false,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:null
     * }
     */
    private static function handoff(string $reason, string $route = 'none'): array
    {
        return [
            'status' => 'handoff',
            'route' => $route,
            'reason' => $reason,
            'executed' => false,
            'evidence_refs' => [],
            'sources' => [],
            'audit' => null,
        ];
    }
}

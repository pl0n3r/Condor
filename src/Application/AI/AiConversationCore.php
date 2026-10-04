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
    private const TOOL_KEYS = ['evidence_ref', 'intent', 'request_ref', 'tenant_id', 'timestamp', 'tool'];

    /**
     * @param array<string, mixed> $turn
     * @param list<KnowledgeArticle> $articles
     * @return array{
     *     status:'ready'|'completed'|'denied'|'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:bool,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:array<string, string>|null,
     *     receipt?:array{tenant_ref:string,tool_ref:string,request_ref:string,decision:'authorized',risk:'read_only'|'reversible_write',outcome:'success'|'denied'|'failure',evidence_ref:string,timestamp:string},
     *     handoff?:array{tenant_ref:string,route:'knowledge'|'tool'|'none',reason:string,evidence_refs:list<string>}
     * }
     */
    public static function turn(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $turn,
        array $articles,
        DateTimeImmutable $at,
        AiToolRegistry $toolRegistry,
    ): array {
        $intent = $turn['intent'] ?? null;
        $tenantId = $turn['tenant_id'] ?? null;

        if (!is_string($intent) || !is_string($tenantId)) {
            return self::handoff($context, 'turn_not_canonical');
        }

        $intent = strtolower(trim($intent));
        $tenantId = trim($tenantId);
        if ($tenantId !== $context->tenantId()) {
            return self::handoff($context, 'tenant_context_mismatch');
        }

        return match ($intent) {
            'knowledge' => self::knowledgeTurn($context, $policy, $turn, $articles, $at),
            'tool' => self::toolTurn($context, $policy, $turn, $toolRegistry),
            default => self::handoff($context, 'intent_not_supported'),
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
     *     audit:null,
     *     handoff?:array{tenant_ref:string,route:'knowledge'|'tool'|'none',reason:string,evidence_refs:list<string>}
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
            return self::knowledgeHandoff($context, 'turn_not_canonical');
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
            return self::knowledgeHandoff($context, 'knowledge_request_invalid');
        }

        if ($result['status'] !== 'ready') {
            return self::knowledgeHandoff(
                $context,
                $result['reason'],
                $result['evidence_refs'],
                $result['sources'],
            );
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
     * @return array{
     *     status:'completed'|'denied'|'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:bool,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:array<string, string>|null,
     *     receipt?:array{tenant_ref:string,tool_ref:string,request_ref:string,decision:'authorized',risk:'read_only'|'reversible_write',outcome:'success'|'denied'|'failure',evidence_ref:string,timestamp:string},
     *     handoff?:array{tenant_ref:string,route:'knowledge'|'tool'|'none',reason:string,evidence_refs:list<string>}
     * }
     */
    private static function toolTurn(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $turn,
        AiToolRegistry $toolRegistry,
    ): array {
        if (!self::hasExactKeys($turn, self::TOOL_KEYS)
            || !is_string($turn['tool'])
            || !is_string($turn['request_ref'])
            || !is_string($turn['evidence_ref'])
            || !is_string($turn['timestamp'])) {
            return self::handoff($context, 'turn_not_canonical', 'tool');
        }

        $decision = AiToolDecision::decide(
            $context,
            $policy,
            [
                'tenant_id' => $turn['tenant_id'],
                'tool' => $turn['tool'],
                'request_ref' => $turn['request_ref'],
            ],
        );
        if ($decision['status'] !== 'authorized') {
            if ($decision['reason'] === 'sensitive_requires_human') {
                return self::handoff(
                    $context,
                    'tool_sensitive_requires_human',
                    'tool',
                );
            }

            return [
                'status' => 'denied',
                'route' => 'tool',
                'reason' => 'tool_request_denied',
                'executed' => false,
                'evidence_refs' => [],
                'sources' => [],
                'audit' => null,
            ];
        }

        try {
            $registration = $toolRegistry->resolve($turn['tool']);
        } catch (DomainException) {
            return self::handoff($context, 'tool_handler_unavailable', 'tool');
        }

        try {
            $result = AiToolInvocation::invoke(
                $context,
                $policy,
                $context->tenantId(),
                $turn['tool'],
                $turn['evidence_ref'],
                $turn['timestamp'],
                $registration['handler'],
            );
        } catch (DomainException) {
            return self::handoff($context, 'tool_request_invalid', 'tool');
        }

        try {
            $receipt = AiToolReceipt::fromArray(
                $context,
                $policy,
                [
                    'request' => $decision['request'],
                    'decision' => $decision,
                    'audit' => $result['audit'],
                ],
            )->snapshot();
        } catch (DomainException) {
            return self::handoff(
                $context,
                'tool_receipt_invalid',
                'tool',
                [$result['evidence_ref']],
                [],
                $result['executed'],
                $result['audit'],
            );
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

        if ($status === 'handoff') {
            $handoff = AiHandoffEnvelope::fromArray(
                $context,
                [
                    'tenant_ref' => 'tenant:' . $context->tenantId(),
                    'route' => 'tool',
                    'reason' => $reason,
                    'evidence_refs' => [$result['evidence_ref']],
                ],
            )->snapshot();

            return [
                'status' => 'handoff',
                'route' => 'tool',
                'reason' => $reason,
                'executed' => $result['executed'],
                'evidence_refs' => [$result['evidence_ref']],
                'sources' => [],
                'audit' => $result['audit'],
                'receipt' => $receipt,
                'handoff' => $handoff,
            ];
        }

        return [
            'status' => $status,
            'route' => 'tool',
            'reason' => $reason,
            'executed' => $result['executed'],
            'evidence_refs' => [$result['evidence_ref']],
            'sources' => [],
            'audit' => $result['audit'],
            'receipt' => $receipt,
        ];
    }

    /**
     * @param list<string> $evidenceRefs
     * @param list<array<string, mixed>> $sources
     * @return array{
     *     status:'handoff',
     *     route:'knowledge',
     *     reason:string,
     *     executed:false,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:null,
     *     handoff:array{tenant_ref:string,route:'knowledge'|'tool'|'none',reason:string,evidence_refs:list<string>}
     * }
     */
    private static function knowledgeHandoff(
        AiTenantContext $context,
        string $reason,
        array $evidenceRefs = [],
        array $sources = [],
    ): array {
        $handoff = AiHandoffEnvelope::fromArray(
            $context,
            [
                'tenant_ref' => 'tenant:' . $context->tenantId(),
                'route' => 'knowledge',
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
            ],
        )->snapshot();

        return [
            'status' => 'handoff',
            'route' => 'knowledge',
            'reason' => $reason,
            'executed' => false,
            'evidence_refs' => $evidenceRefs,
            'sources' => $sources,
            'audit' => null,
            'handoff' => $handoff,
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
     * @param list<string> $evidenceRefs
     * @param list<array<string, mixed>> $sources
     * @param array<string, string>|null $audit
     * @return array{
     *     status:'handoff',
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     executed:bool,
     *     evidence_refs:list<string>,
     *     sources:list<array<string, mixed>>,
     *     audit:array<string, string>|null,
     *     handoff:array{tenant_ref:string,route:'knowledge'|'tool'|'none',reason:string,evidence_refs:list<string>}
     * }
     */
    private static function handoff(
        AiTenantContext $context,
        string $reason,
        string $route = 'none',
        array $evidenceRefs = [],
        array $sources = [],
        bool $executed = false,
        ?array $audit = null,
    ): array {
        $handoff = AiHandoffEnvelope::fromArray(
            $context,
            [
                'tenant_ref' => 'tenant:' . $context->tenantId(),
                'route' => $route,
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
            ],
        )->snapshot();

        return [
            'status' => 'handoff',
            'route' => $route,
            'reason' => $reason,
            'executed' => $executed,
            'evidence_refs' => $evidenceRefs,
            'sources' => $sources,
            'audit' => $audit,
            'handoff' => $handoff,
        ];
    }
}

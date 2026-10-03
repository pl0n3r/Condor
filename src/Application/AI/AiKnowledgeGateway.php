<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Knowledge\KnowledgeRetrieval;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;

final class AiKnowledgeGateway
{
    private const REQUEST_KEYS = [
        'visibility',
        'locale',
        'module',
        'evidence_confidence',
        'minimum_sources',
    ];

    /**
     * @param list<KnowledgeArticle> $articles
     * @param array<string, mixed> $request
     * @return array{
     *     status:'ready'|'handoff',
     *     reason:string,
     *     evidence_refs:list<string>,
     *     sources:list<array{
     *         knowledge_ref:string,
     *         source_ref:string,
     *         knowledge_fingerprint:string,
     *         version:int
     *     }>
     * }
     */
    public static function retrieve(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $articles,
        array $request,
        DateTimeImmutable $at,
    ): array {
        if ($context->tool() !== 'knowledge.read'
            || !$policy->allowsAutonomousExecution('knowledge.read')) {
            return self::handoff('tool_not_authorized');
        }

        if (!self::requestIsCanonical($request)) {
            return self::handoff('request_not_canonical');
        }

        $allowedRefs = $context->knowledgeRefs();
        if ($allowedRefs === []) {
            return self::handoff('knowledge_scope_empty');
        }

        $allowlist = array_fill_keys($allowedRefs, true);
        $tenantScope = 'tenant:' . $context->tenantId();
        $permitted = [];

        foreach ($articles as $article) {
            if (!$article instanceof KnowledgeArticle) {
                return self::handoff('invalid_knowledge_input');
            }

            $snapshot = $article->snapshot();
            $knowledgeRef = 'knowledge:' . $snapshot['id'];
            if (!isset($allowlist[$knowledgeRef])) {
                continue;
            }

            $scope = $snapshot['scope'];
            if ($scope !== 'global' && $scope !== $tenantScope) {
                continue;
            }

            $permitted[] = $article;
        }

        $retrieval = KnowledgeRetrieval::retrieve(
            $permitted,
            [
                'visibility' => $request['visibility'],
                'locale' => $request['locale'],
                'module' => $request['module'],
                'scope' => $tenantScope,
                'evidence_confidence' => $request['evidence_confidence'],
                'minimum_sources' => $request['minimum_sources'],
            ],
            $at,
        );

        [$evidenceRefs, $sources, $valid] = self::minimalEvidence(
            $retrieval['evidence'],
            $allowlist,
            $tenantScope,
        );
        if (!$valid) {
            return self::handoff('evidence_outside_authorized_scope');
        }

        if ($retrieval['status'] !== 'ready') {
            return self::handoff((string) $retrieval['reason'], $evidenceRefs, $sources);
        }

        return [
            'status' => 'ready',
            'reason' => 'evidence_sufficient',
            'evidence_refs' => $evidenceRefs,
            'sources' => $sources,
        ];
    }

    /** @param array<string, mixed> $request */
    private static function requestIsCanonical(array $request): bool
    {
        $keys = array_keys($request);
        sort($keys);
        $expected = self::REQUEST_KEYS;
        sort($expected);

        return $keys === $expected;
    }

    /**
     * @param array<int, array<string, mixed>> $evidence
     * @param array<string, bool> $allowlist
     * @return array{
     *     0:list<string>,
     *     1:list<array{
     *         knowledge_ref:string,
     *         source_ref:string,
     *         knowledge_fingerprint:string,
     *         version:int
     *     }>,
     *     2:bool
     * }
     */
    private static function minimalEvidence(
        array $evidence,
        array $allowlist,
        string $tenantScope,
    ): array {
        $refs = [];
        $sources = [];

        foreach ($evidence as $item) {
            $knowledgeRef = 'knowledge:' . $item['knowledge_id'];
            if (!isset($allowlist[$knowledgeRef])) {
                return [[], [], false];
            }

            if ($item['scope'] !== 'global' && $item['scope'] !== $tenantScope) {
                return [[], [], false];
            }

            $refs[] = $knowledgeRef;
            $sources[] = [
                'knowledge_ref' => $knowledgeRef,
                'source_ref' => (string) $item['source_ref'],
                'knowledge_fingerprint' => (string) $item['knowledge_fingerprint'],
                'version' => (int) $item['version'],
            ];
        }

        return [$refs, $sources, true];
    }

    /**
     * @param list<string> $evidenceRefs
     * @param list<array{
     *     knowledge_ref:string,
     *     source_ref:string,
     *     knowledge_fingerprint:string,
     *     version:int
     * }> $sources
     * @return array{
     *     status:'handoff',
     *     reason:string,
     *     evidence_refs:list<string>,
     *     sources:list<array{
     *         knowledge_ref:string,
     *         source_ref:string,
     *         knowledge_fingerprint:string,
     *         version:int
     *     }>
     * }
     */
    private static function handoff(
        string $reason,
        array $evidenceRefs = [],
        array $sources = [],
    ): array {
        return [
            'status' => 'handoff',
            'reason' => $reason,
            'evidence_refs' => $evidenceRefs,
            'sources' => $sources,
        ];
    }
}

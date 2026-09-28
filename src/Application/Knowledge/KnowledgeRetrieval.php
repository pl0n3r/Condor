<?php

declare(strict_types=1);

namespace App\Application\Knowledge;

use App\Domain\Knowledge\KnowledgeArticle;
use DateTimeImmutable;
use DomainException;

final class KnowledgeRetrieval
{
    private const VISIBILITIES = ['public', 'customer', 'staff'];
    private const CONFIDENCE = ['verified', 'sufficient', 'insufficient', 'unknown'];
    private const REQUEST_KEYS = [
        'visibility', 'locale', 'module', 'scope', 'evidence_confidence', 'minimum_sources',
    ];

    /**
     * @param list<KnowledgeArticle> $articles
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function retrieve(array $articles, array $request, DateTimeImmutable $at): array
    {
        self::assertRequest($request);

        $visibility = $request['visibility'];
        $locale = $request['locale'];
        $module = $request['module'];
        $scope = $request['scope'];
        $confidence = $request['evidence_confidence'];
        $minimumSources = $request['minimum_sources'];

        $selected = [];
        foreach ($articles as $article) {
            if (!$article instanceof KnowledgeArticle) {
                throw new DomainException('Retrieval requiere KnowledgeArticle normalizado.');
            }

            $snapshot = $article->snapshot();
            if (!self::eligible($article, $snapshot, $visibility, $locale, $module, $scope, $at)) {
                continue;
            }

            $selected[] = [
                'knowledge_id' => $snapshot['id'],
                'version' => $snapshot['version'],
                'title' => $snapshot['title'],
                'body' => $snapshot['body'],
                'visibility' => $snapshot['visibility'],
                'locale' => $snapshot['locale'],
                'scope' => $snapshot['scope'],
                'module' => $module,
                'source_ref' => $snapshot['source_ref'],
                'knowledge_fingerprint' => $article->versionFingerprint(),
                'reviewed_at' => $snapshot['reviewed_at'],
                'stale_after' => $snapshot['stale_after'],
            ];
        }

        usort(
            $selected,
            static fn (array $a, array $b): int =>
                [$a['knowledge_id'], $a['version']] <=> [$b['knowledge_id'], $b['version']],
        );

        $enoughEvidence = count($selected) >= $minimumSources;
        $confidenceEnough = in_array($confidence, ['verified', 'sufficient'], true);

        if (!$enoughEvidence || !$confidenceEnough) {
            return [
                'status' => 'handoff',
                'reason' => !$confidenceEnough ? 'confidence_insufficient' : 'evidence_insufficient',
                'channel_contract' => 'support-context-v1',
                'scope' => $scope,
                'visibility' => $visibility,
                'locale' => $locale,
                'module' => $module,
                'evidence_confidence' => $confidence,
                'minimum_sources' => $minimumSources,
                'evidence_count' => count($selected),
                'evidence' => $selected,
            ];
        }

        return [
            'status' => 'ready',
            'reason' => 'evidence_sufficient',
            'channel_contract' => 'support-context-v1',
            'scope' => $scope,
            'visibility' => $visibility,
            'locale' => $locale,
            'module' => $module,
            'evidence_confidence' => $confidence,
            'minimum_sources' => $minimumSources,
            'evidence_count' => count($selected),
            'evidence' => $selected,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function eligible(
        KnowledgeArticle $article,
        array $snapshot,
        string $visibility,
        string $locale,
        string $module,
        string $scope,
        DateTimeImmutable $at,
    ): bool {
        if ($snapshot['state'] !== 'published') return false;
        if ($article->isStaleAt($at)) return false;
        if ($snapshot['visibility'] !== $visibility || $snapshot['audience'] !== $visibility) return false;
        if ($snapshot['locale'] !== $locale) return false;
        if (!in_array($module, $snapshot['modules'], true)) return false;

        $articleScope = $snapshot['scope'];
        if ($articleScope === 'global') {
            return true;
        }

        return $scope !== 'global' && $articleScope === $scope;
    }

    /** @param array<string, mixed> $request */
    private static function assertRequest(array $request): void
    {
        $keys = array_keys($request);
        sort($keys);
        $expected = self::REQUEST_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new DomainException('Request de retrieval incompleto o con campos no soportados.');
        }

        if (!is_string($request['visibility']) || !in_array($request['visibility'], self::VISIBILITIES, true)) {
            throw new DomainException('Visibility de retrieval inválida.');
        }
        if (!is_string($request['locale'])
            || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $request['locale']) !== 1) {
            throw new DomainException('Locale de retrieval inválido.');
        }
        if (!is_string($request['module'])
            || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $request['module']) !== 1) {
            throw new DomainException('Module de retrieval inválido.');
        }
        if (!is_string($request['scope'])
            || ($request['scope'] !== 'global'
                && preg_match('/^tenant:[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $request['scope']) !== 1)) {
            throw new DomainException('Scope de retrieval inválido.');
        }
        if ($request['visibility'] === 'public' && $request['scope'] !== 'global') {
            throw new DomainException('Retrieval público no puede usar tenant scope.');
        }
        if (!is_string($request['evidence_confidence'])
            || !in_array($request['evidence_confidence'], self::CONFIDENCE, true)) {
            throw new DomainException('Confidence de retrieval inválida.');
        }
        if (!is_int($request['minimum_sources'])
            || $request['minimum_sources'] < 1
            || $request['minimum_sources'] > 10) {
            throw new DomainException('minimum_sources inválido.');
        }
    }
}

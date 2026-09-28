<?php

declare(strict_types=1);

namespace App\Application\KnowledgeGap;

use DomainException;

final class FactoryWorkItemAdapter
{
    private const WORK_TYPES = ['knowledge_documentation', 'content', 'product'];
    private const PRIORITIES = ['critical', 'high', 'medium'];
    private const CONTEXT_KEYS = [
        'group_id', 'venture_id', 'project_id', 'repository_ref', 'work_type',
        'requested_capabilities', 'required_roles', 'authority_level',
        'producer_ref', 'priority_class', 'claims', 'policy_ref',
    ];

    /**
     * @param array<string, mixed> $gap
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function toWorkItem(array $gap, array $context): array
    {
        self::assertGap($gap);
        self::assertContext($context);

        $fingerprint = (string) $gap['fingerprint'];
        $workType = (string) $context['work_type'];
        $evidenceRefs = array_values(array_unique(array_merge(
            self::strings($gap['evidence_refs'], 'gap.evidence_refs'),
            self::strings($gap['source_refs'], 'gap.source_refs'),
        )));
        sort($evidenceRefs, SORT_STRING);

        return [
            'work_id' => sprintf('knowledge-gap:%s:%s', substr($fingerprint, 0, 20), $workType),
            'origin_mode' => 'automatic',
            'origin_system' => 'product',
            'group_id' => self::text($context['group_id'], 'group_id'),
            'venture_id' => self::text($context['venture_id'], 'venture_id'),
            'project_id' => self::text($context['project_id'], 'project_id'),
            'repository_ref' => self::repository($context['repository_ref']),
            'work_type' => $workType,
            'requested_capabilities' => self::slugs(
                $context['requested_capabilities'],
                'requested_capabilities',
                false,
            ),
            'required_roles' => self::slugs($context['required_roles'], 'required_roles', false),
            'authority_level' => self::slug($context['authority_level'], 'authority_level'),
            'producer_ref' => self::text($context['producer_ref'], 'producer_ref'),
            'priority_class' => (string) $context['priority_class'],
            'depends_on' => [],
            'claims' => self::strings($context['claims'], 'claims'),
            'policy_ref' => self::text($context['policy_ref'], 'policy_ref'),
            'evidence_refs' => $evidenceRefs,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $gap['last_observed_at']),
            'idempotency_key' => sprintf('knowledge-gap:%s:%s', $fingerprint, $workType),
        ];
    }

    /** @param array<string, mixed> $gap */
    private static function assertGap(array $gap): void
    {
        $required = [
            'fingerprint', 'locale', 'module', 'scope',
            'evidence_refs', 'source_refs', 'first_observed_at', 'last_observed_at',
            'unanswered_count', 'escalated_count',
        ];
        $keys = array_keys($gap);
        sort($keys);
        sort($required);
        if ($keys !== $required) {
            throw new DomainException('KnowledgeGap agregado fuera de contrato.');
        }

        $fingerprint = $gap['fingerprint'] ?? null;
        if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new DomainException('Fingerprint de KnowledgeGap inválido.');
        }

        foreach (['first_observed_at', 'last_observed_at'] as $field) {
            if (!is_int($gap[$field]) || $gap[$field] < 1) {
                throw new DomainException($field . ' inválido.');
            }
        }
        if ($gap['last_observed_at'] < $gap['first_observed_at']) {
            throw new DomainException('Ventana temporal de KnowledgeGap inválida.');
        }
        foreach (['unanswered_count', 'escalated_count'] as $field) {
            if (!is_int($gap[$field]) || $gap[$field] < 0) {
                throw new DomainException($field . ' inválido.');
            }
        }

        self::strings($gap['evidence_refs'], 'gap.evidence_refs');
        self::strings($gap['source_refs'], 'gap.source_refs');
    }

    /** @param array<string, mixed> $context */
    private static function assertContext(array $context): void
    {
        $keys = array_keys($context);
        sort($keys);
        $expected = self::CONTEXT_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new DomainException('Contexto WorkItem incompleto o con campos no soportados.');
        }

        if (!is_string($context['work_type']) || !in_array($context['work_type'], self::WORK_TYPES, true)) {
            throw new DomainException('work_type de KnowledgeGap fuera del catálogo.');
        }
        if (!is_string($context['priority_class'])
            || !in_array($context['priority_class'], self::PRIORITIES, true)) {
            throw new DomainException('priority_class fuera del catálogo.');
        }
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new DomainException($field . ' debe ser una lista acotada.');
        }

        $result = [];
        foreach ($value as $item) {
            $result[] = self::text($item, $field . '[]');
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private static function slugs(mixed $value, string $field, bool $allowEmpty = true): array
    {
        $items = self::strings($value, $field);
        if (!$allowEmpty && $items === []) {
            throw new DomainException($field . ' no puede estar vacío.');
        }

        foreach ($items as $item) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $item) !== 1) {
                throw new DomainException($field . ' contiene un slug inválido.');
            }
        }

        return $items;
    }

    private static function slug(mixed $value, string $field): string
    {
        $text = self::text($value, $field);
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $text) !== 1) {
            throw new DomainException($field . ' debe ser un slug canónico.');
        }
        return $text;
    }

    private static function repository(mixed $value): string
    {
        $text = self::text($value, 'repository_ref');
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $text) !== 1) {
            throw new DomainException('repository_ref inválido.');
        }
        return $text;
    }

    private static function text(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new DomainException($field . ' debe ser texto.');
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 240
            || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")
            || preg_match('/(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|\bBearer\s+[A-Za-z0-9._~+\/=\-]{10,}|\bgithub_pat_[A-Za-z0-9_]{10,}|\bgh[pousr]_[A-Za-z0-9]{20,}|\bsk-[A-Za-z0-9]{20,})/i', $value) === 1) {
            throw new DomainException($field . ' fuera de contrato o con forma de secreto.');
        }

        return $value;
    }
}

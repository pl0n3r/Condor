<?php

declare(strict_types=1);

namespace App\Application\Market;

use App\Domain\Market\Market;
use DomainException;

final class MarketReadiness
{
    private const GATES = [
        'product',
        'lex',
        'privacy',
        'localization',
        'currency_pricing',
        'payments_billing',
        'support_knowledge',
        'infrastructure',
        'security',
        'analytics',
        'capital',
    ];
    private const STATUSES = ['SATISFIED', 'GAP', 'UNKNOWN', 'NOT_APPLICABLE'];
    private const FRESHNESS = ['FRESH', 'STALE', 'UNKNOWN'];

    /**
     * @param array<string, array<string, mixed>> $gates
     * @return array<string, mixed>
     */
    public static function evaluate(Market $market, array $gates): array
    {
        self::assertGateNames($gates);

        $normalized = [];
        $blockers = [];
        $allEvidence = [];
        $freshness = 'FRESH';

        foreach (self::GATES as $name) {
            $gate = self::normalizeGate($name, $gates[$name]);
            $normalized[$name] = $gate;
            foreach ($gate['evidence_refs'] as $ref) {
                $allEvidence[$ref] = true;
            }

            if ($gate['freshness'] !== 'FRESH') {
                $freshness = 'DEGRADED';
            }

            $passed = in_array($gate['status'], ['SATISFIED', 'NOT_APPLICABLE'], true)
                && $gate['freshness'] === 'FRESH'
                && $gate['evidence_refs'] !== [];

            if ($name === 'lex' && $gate['status'] !== 'SATISFIED') {
                $passed = false;
            }

            if (!$passed) {
                $blockers[] = [
                    'gate' => $name,
                    'status' => $gate['status'],
                    'freshness' => $gate['freshness'],
                    'reason' => self::blockerReason($name, $gate),
                    'evidence_refs' => $gate['evidence_refs'],
                ];
            }
        }

        $evidenceRefs = array_keys($allEvidence);
        sort($evidenceRefs, SORT_STRING);
        $snapshot = $market->snapshot();
        if (!in_array($snapshot['source_ref'], $evidenceRefs, true)) {
            $evidenceRefs[] = $snapshot['source_ref'];
            sort($evidenceRefs, SORT_STRING);
        }

        $launchAllowed = $blockers === [];
        $lexStatus = $normalized['lex']['status'];

        return [
            'market_context' => $market->contextKey(),
            'market_id' => $snapshot['market_id'],
            'tenant_id' => $snapshot['tenant_id'],
            'venture_id' => $snapshot['venture_id'],
            'country_code' => $snapshot['country_code'],
            'source_ref' => $snapshot['source_ref'],
            'observed_at' => $snapshot['observed_at'],
            'launch_allowed' => $launchAllowed,
            'evidence_freshness' => $freshness,
            'lex_status' => $lexStatus,
            'gates' => $normalized,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
            'authorization' => [
                'market_context' => $market->contextKey(),
                'launch_allowed' => $launchAllowed,
                'lex_status' => $lexStatus,
                'evidence_freshness' => $freshness,
                'evidence_refs' => $evidenceRefs,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public static function toFactoryWorkItems(array $result, array $context): array
    {
        self::assertResult($result);
        self::assertFactoryContext($context);

        $items = [];
        foreach ($result['blockers'] as $blocker) {
            $gate = $blocker['gate'];
            $evidence = array_values(array_unique(array_merge(
                $blocker['evidence_refs'],
                [$result['source_ref']],
            )));
            sort($evidence, SORT_STRING);

            $items[] = [
                'work_id' => sprintf(
                    'market-readiness:%s:%s',
                    $result['market_id'],
                    $gate,
                ),
                'origin_mode' => 'automatic',
                'origin_system' => 'product',
                'group_id' => $context['group_id'],
                'venture_id' => $result['venture_id'],
                'project_id' => $context['project_id'],
                'repository_ref' => $context['repository_ref'],
                'work_type' => self::workType($gate),
                'requested_capabilities' => ['market_readiness'],
                'required_roles' => [self::role($gate)],
                'authority_level' => $context['authority_level'],
                'producer_ref' => $context['producer_ref'],
                'priority_class' => 'high',
                'depends_on' => [],
                'claims' => [
                    sprintf(
                        'market:%s:%s:%s',
                        $result['tenant_id'],
                        $result['market_id'],
                        $gate,
                    ),
                ],
                'policy_ref' => $context['policy_ref'],
                'evidence_refs' => $evidence,
                'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $result['observed_at']),
                'idempotency_key' => sprintf(
                    'market-readiness:%s:%s:%s',
                    $result['tenant_id'],
                    $result['market_id'],
                    $gate,
                ),
            ];
        }

        return $items;
    }

    /** @param array<string, array<string, mixed>> $gates */
    private static function assertGateNames(array $gates): void
    {
        $keys = array_keys($gates);
        sort($keys, SORT_STRING);
        $expected = self::GATES;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new DomainException('Market Readiness requiere el catálogo exacto de gates.');
        }
    }

    /** @param array<string, mixed> $gate @return array<string, mixed> */
    private static function normalizeGate(string $name, array $gate): array
    {
        $keys = array_keys($gate);
        sort($keys, SORT_STRING);
        $expected = ['status', 'evidence_refs', 'freshness'];
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new DomainException('Gate '.$name.' fuera de contrato.');
        }

        $status = $gate['status'] ?? null;
        $freshness = $gate['freshness'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new DomainException('Status de gate inválido.');
        }
        if (!is_string($freshness) || !in_array($freshness, self::FRESHNESS, true)) {
            throw new DomainException('Freshness de gate inválida.');
        }

        $refs = self::references($gate['evidence_refs'] ?? null);

        return [
            'status' => $status,
            'evidence_refs' => $refs,
            'freshness' => $freshness,
        ];
    }

    /** @param array<string, mixed> $gate */
    private static function blockerReason(string $name, array $gate): string
    {
        if ($name === 'lex' && $gate['status'] !== 'SATISFIED') {
            return 'lex_not_satisfied';
        }
        if ($gate['freshness'] !== 'FRESH') {
            return 'evidence_not_fresh';
        }
        if ($gate['evidence_refs'] === []) {
            return 'evidence_missing';
        }
        if ($gate['status'] === 'UNKNOWN') {
            return 'status_unknown';
        }
        if ($gate['status'] === 'GAP') {
            return 'gap_open';
        }

        return 'gate_not_satisfied';
    }

    /** @return list<string> */
    private static function references(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new DomainException('evidence_refs inválido.');
        }

        $refs = [];
        foreach ($value as $ref) {
            if (!is_string($ref)
                || strlen($ref) > 200
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@#-]*$/D', $ref) !== 1) {
                throw new DomainException('evidence_ref inválido.');
            }
            $refs[$ref] = true;
        }

        $result = array_keys($refs);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param array<string, mixed> $result */
    private static function assertResult(array $result): void
    {
        foreach ([
            'market_id',
            'tenant_id',
            'venture_id',
            'source_ref',
            'observed_at',
            'blockers',
        ] as $field) {
            if (!array_key_exists($field, $result)) {
                throw new DomainException('Resultado de Market Readiness incompleto.');
            }
        }

        if (!is_array($result['blockers']) || !array_is_list($result['blockers'])) {
            throw new DomainException('Blockers de Market Readiness inválidos.');
        }
        if (!is_int($result['observed_at']) || $result['observed_at'] < 1) {
            throw new DomainException('observed_at de Market Readiness inválido.');
        }
    }

    /** @param array<string, mixed> $context */
    private static function assertFactoryContext(array $context): void
    {
        $required = [
            'group_id',
            'project_id',
            'repository_ref',
            'producer_ref',
            'authority_level',
            'policy_ref',
        ];
        $keys = array_keys($context);
        sort($keys, SORT_STRING);
        sort($required, SORT_STRING);
        if ($keys !== $required) {
            throw new DomainException('Contexto Factory incompleto o con campos no soportados.');
        }

        foreach ($context as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 200) {
                throw new DomainException('Contexto Factory inválido.');
            }
        }
    }

    private static function workType(string $gate): string
    {
        return match ($gate) {
            'lex', 'privacy' => 'compliance_review',
            'security' => 'security',
            'infrastructure' => 'infrastructure',
            'localization', 'support_knowledge' => 'knowledge_documentation',
            'analytics' => 'data_analytics',
            'capital' => 'finance_analysis',
            default => 'product',
        };
    }

    private static function role(string $gate): string
    {
        return match ($gate) {
            'lex', 'privacy' => 'legal-privacidad',
            'security' => 'seguridad',
            'infrastructure' => 'infraestructura',
            'localization', 'support_knowledge' => 'contenido',
            'analytics' => 'datos-analitica',
            'capital' => 'datos-analitica',
            default => 'producto',
        };
    }
}

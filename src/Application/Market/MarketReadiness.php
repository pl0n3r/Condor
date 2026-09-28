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

        /** @var array<string, array{status:string,evidence_refs:list<string>,freshness:string}> $normalized */
        $normalized = [];
        /** @var list<array{gate:string,status:string,freshness:string,reason:string,evidence_refs:list<string>}> $blockers */
        $blockers = [];
        /** @var array<string, true> $allEvidence */
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
        $marketId = self::resultString($result, 'market_id');
        $tenantId = self::resultString($result, 'tenant_id');
        $ventureId = self::resultString($result, 'venture_id');
        $sourceRef = self::resultString($result, 'source_ref');
        $observedAt = $result['observed_at'] ?? null;
        if (!is_int($observedAt) || $observedAt < 1) {
            throw new DomainException('observed_at de Market Readiness inválido.');
        }

        $blockers = self::normalizeBlockers($result['blockers'] ?? null);
        $factory = self::normalizeFactoryContext($context);

        $items = [];
        foreach ($blockers as $blocker) {
            $gate = $blocker['gate'];
            $evidence = array_values(array_unique(array_merge(
                $blocker['evidence_refs'],
                [$sourceRef],
            )));
            sort($evidence, SORT_STRING);

            $items[] = [
                'work_id' => sprintf(
                    'market-readiness:%s:%s',
                    $marketId,
                    $gate,
                ),
                'origin_mode' => 'automatic',
                'origin_system' => 'product',
                'group_id' => $factory['group_id'],
                'venture_id' => $ventureId,
                'project_id' => $factory['project_id'],
                'repository_ref' => $factory['repository_ref'],
                'work_type' => self::workType($gate),
                'requested_capabilities' => ['market_readiness'],
                'required_roles' => [self::role($gate)],
                'authority_level' => $factory['authority_level'],
                'producer_ref' => $factory['producer_ref'],
                'priority_class' => 'high',
                'depends_on' => [],
                'claims' => [
                    sprintf(
                        'market:%s:%s:%s',
                        $tenantId,
                        $marketId,
                        $gate,
                    ),
                ],
                'policy_ref' => $factory['policy_ref'],
                'evidence_refs' => $evidence,
                'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $observedAt),
                'idempotency_key' => sprintf(
                    'market-readiness:%s:%s:%s',
                    $tenantId,
                    $marketId,
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

    /**
     * @param array<string, mixed> $gate
     * @return array{status:string,evidence_refs:list<string>,freshness:string}
     */
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

    /**
     * @param array{status:string,evidence_refs:list<string>,freshness:string} $gate
     */
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
    private static function resultString(array $result, string $field): string
    {
        $value = $result[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 200) {
            throw new DomainException($field.' de Market Readiness inválido.');
        }

        return $value;
    }

    /**
     * @return list<array{gate:string,status:string,freshness:string,reason:string,evidence_refs:list<string>}>
     */
    private static function normalizeBlockers(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > count(self::GATES)) {
            throw new DomainException('Blockers de Market Readiness inválidos.');
        }

        $result = [];
        foreach ($value as $blocker) {
            if (!is_array($blocker) || array_is_list($blocker)) {
                throw new DomainException('Blocker de Market Readiness inválido.');
            }

            $keys = array_keys($blocker);
            sort($keys, SORT_STRING);
            $expected = ['gate', 'status', 'freshness', 'reason', 'evidence_refs'];
            sort($expected, SORT_STRING);
            if ($keys !== $expected) {
                throw new DomainException('Blocker de Market Readiness fuera de contrato.');
            }

            $gate = $blocker['gate'] ?? null;
            $status = $blocker['status'] ?? null;
            $freshness = $blocker['freshness'] ?? null;
            $reason = $blocker['reason'] ?? null;
            if (!is_string($gate) || !in_array($gate, self::GATES, true)
                || !is_string($status) || !in_array($status, self::STATUSES, true)
                || !is_string($freshness) || !in_array($freshness, self::FRESHNESS, true)
                || !is_string($reason) || $reason === '') {
                throw new DomainException('Blocker de Market Readiness inválido.');
            }

            $result[] = [
                'gate' => $gate,
                'status' => $status,
                'freshness' => $freshness,
                'reason' => $reason,
                'evidence_refs' => self::references($blocker['evidence_refs'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *   group_id:string,
     *   project_id:string,
     *   repository_ref:string,
     *   producer_ref:string,
     *   authority_level:string,
     *   policy_ref:string
     * }
     */
    private static function normalizeFactoryContext(array $context): array
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

        $normalized = [];
        foreach ($required as $field) {
            $value = $context[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > 200) {
                throw new DomainException('Contexto Factory inválido.');
            }
            $normalized[$field] = $value;
        }

        return [
            'group_id' => $normalized['group_id'],
            'project_id' => $normalized['project_id'],
            'repository_ref' => $normalized['repository_ref'],
            'producer_ref' => $normalized['producer_ref'],
            'authority_level' => $normalized['authority_level'],
            'policy_ref' => $normalized['policy_ref'],
        ];
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

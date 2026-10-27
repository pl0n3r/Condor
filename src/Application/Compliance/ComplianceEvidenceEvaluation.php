<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * M06 S05-S07: deterministic, synthetic-only evidence evaluation preview.
 * CUMPLE means an explicitly human-approved fixture is current, never a legal
 * certification or an automatic approval/closure. No I/O or persistence.
 */
final class ComplianceEvidenceEvaluation
{
    private const OBLIGATION_KEYS = [
        'company_ref', 'effective_from', 'effective_until', 'obligation_ref',
        'source_ref', 'tenant_ref',
    ];
    private const EVIDENCE_KEYS = [
        'company_ref', 'evidence_ref', 'issued_on', 'obligation_ref',
        'review_state', 'reviewed_on', 'reviewer_ref', 'tenant_ref', 'valid_until',
    ];
    private const MAX_ITEMS = 100;
    private const WARNING_DAYS = 30;

    /**
     * @param array<array-key,mixed> $payload No user data; only fixture references.
     * @return array{tenant_ref:string,company_ref:string,synthetic_only:true,automatic_approval:false,automatic_closure:false,rows:list<array<string,mixed>>,pending_evaluations:list<array<string,string>>}
     */
    public static function project(array $payload): array
    {
        if (!self::exactKeys($payload, [
            'company_ref', 'evidence', 'obligations', 'reference_date', 'tenant_ref',
        ]) || !self::fixture($payload['tenant_ref'], 'tenant')
            || !self::fixture($payload['company_ref'], 'company')
            || !is_array($payload['obligations']) || !array_is_list($payload['obligations'])
            || count($payload['obligations']) > self::MAX_ITEMS
            || !is_array($payload['evidence']) || !array_is_list($payload['evidence'])
            || count($payload['evidence']) > self::MAX_ITEMS
        ) {
            throw new \InvalidArgumentException('Invalid synthetic compliance payload');
        }
        $reference = self::date($payload['reference_date']);
        if ($reference === null) {
            throw new \InvalidArgumentException('Invalid synthetic reference date');
        }
        $tenant = $payload['tenant_ref'];
        $company = $payload['company_ref'];
        $obligations = [];
        foreach ($payload['obligations'] as $item) {
            if (!is_array($item) || !self::exactKeys($item, self::OBLIGATION_KEYS)
                || !self::fixture($item['tenant_ref'], 'tenant')
                || !self::fixture($item['company_ref'], 'company')
                || !self::fixture($item['obligation_ref'], 'obligation')
                || !self::fixture($item['source_ref'], 'source')
            ) {
                throw new \InvalidArgumentException('Invalid synthetic obligation');
            }
            // Valid foreign scope is never displayed or inspected for findings.
            if ($item['tenant_ref'] !== $tenant || $item['company_ref'] !== $company) {
                continue;
            }
            $start = self::date($item['effective_from']);
            $end = self::date($item['effective_until']);
            if ($start === null || $end === null || $start > $end
                || isset($obligations[$item['obligation_ref']])
            ) {
                throw new \InvalidArgumentException('Ambiguous synthetic obligation');
            }
            $obligations[$item['obligation_ref']] = [$item, $start, $end];
        }

        $evidence = [];
        foreach ($payload['evidence'] as $item) {
            if (!is_array($item) || !self::exactKeys($item, self::EVIDENCE_KEYS)
                || !self::fixture($item['tenant_ref'], 'tenant')
                || !self::fixture($item['company_ref'], 'company')
                || !self::fixture($item['obligation_ref'], 'obligation')
                || !self::fixture($item['evidence_ref'], 'evidence')
            ) {
                throw new \InvalidArgumentException('Invalid synthetic evidence');
            }
            if ($item['tenant_ref'] !== $tenant || $item['company_ref'] !== $company) {
                continue;
            }
            $issued = self::date($item['issued_on']);
            $validUntil = self::date($item['valid_until']);
            if ($issued === null || $validUntil === null || $issued > $validUntil
                || !in_array($item['review_state'], ['PENDIENTE', 'APROBADA', 'RECHAZADA'], true)
                || isset($evidence[$item['obligation_ref']])
                || !isset($obligations[$item['obligation_ref']])
            ) {
                throw new \InvalidArgumentException('Ambiguous synthetic evidence');
            }
            $reviewed = $item['reviewed_on'] === null ? null : self::date($item['reviewed_on']);
            $reviewer = $item['reviewer_ref'];
            if ($item['review_state'] === 'APROBADA') {
                if (!self::fixture($reviewer, 'reviewer') || $reviewed === null
                    || $reviewed < $issued || $reviewed > $reference
                ) {
                    throw new \InvalidArgumentException('Missing prior human fixture approval');
                }
            } elseif ($reviewed !== null || $reviewer !== null) {
                throw new \InvalidArgumentException('Ambiguous synthetic review');
            }
            $evidence[$item['obligation_ref']] = [$item, $issued, $validUntil];
        }

        $rows = [];
        $pending = [];
        foreach ($obligations as $referenceKey => [$obligation, $effective, $end]) {
            $candidate = $evidence[$referenceKey] ?? null;
            $state = 'SIN_EVIDENCIA';
            $reason = 'SIN_EVIDENCIA_VERIFICADA';
            $ref = null;
            if ($effective > $reference || $end < $reference) {
                $reason = 'VIGENCIA_NORMATIVA_REQUIERE_REVISION';
            } elseif ($candidate !== null) {
                [$record, $issued, $validUntil] = $candidate;
                $ref = $record['evidence_ref'];
                if ($record['review_state'] !== 'APROBADA') {
                    $reason = 'REVISION_HUMANA_PENDIENTE';
                } elseif ($issued < $effective || $issued > $end) {
                    $reason = 'FECHA_EVIDENCIA_REQUIERE_REVISION';
                } elseif ($validUntil < $reference) {
                    $state = 'VENCIDA';
                    $reason = 'EVIDENCIA_VENCIDA';
                } elseif ((int) $reference->diff($validUntil)->format('%a') <= self::WARNING_DAYS) {
                    $state = 'POR_VENCER';
                    $reason = 'EVIDENCIA_POR_VENCER';
                } else {
                    // Human-approved *fixture* only. Never triggers closure or submission.
                    $state = 'CUMPLE';
                    $reason = 'APROBACION_SINTETICA_VIGENTE';
                }
            }
            $rows[] = [
                'obligation_ref' => $referenceKey,
                'state' => $state,
                'evidence_ref' => $ref,
                'synthetic_only' => true,
                'requires_professional_review' => true,
                'automatically_closed' => false,
                'automatically_approved' => false,
            ];
            if ($state !== 'CUMPLE') {
                $pending[] = ['obligation_ref' => $referenceKey, 'reason' => $reason];
            }
        }
        return [
            'tenant_ref' => $tenant,
            'company_ref' => $company,
            'synthetic_only' => true,
            'automatic_approval' => false,
            'automatic_closure' => false,
            'rows' => $rows,
            'pending_evaluations' => $pending,
        ];
    }

    private static function fixture(mixed $value, string $type): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parts = explode('/', $value, 2);
        if (count($parts) !== 2 || $parts[0] !== 'fixture:' . $type) {
            return false;
        }
        $identifier = $parts[1];
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,47}\z/', $identifier) === 1;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) !== 10) {
            return null;
        }
        $fragments = explode('-', $value);
        if (count($fragments) !== 3
            || strlen($fragments[0]) !== 4
            || strlen($fragments[1]) !== 2
            || strlen($fragments[2]) !== 2
            || !ctype_digit($fragments[0])
            || !ctype_digit($fragments[1])
            || !ctype_digit($fragments[2])
            || !checkdate((int) $fragments[1], (int) $fragments[2], (int) $fragments[0])
        ) {
            return null;
        }
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string> $expected
     */
    private static function exactKeys(array $payload, array $expected): bool
    {
        if (count($payload) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $payload)) {
                return false;
            }
        }
        return true;
    }
}

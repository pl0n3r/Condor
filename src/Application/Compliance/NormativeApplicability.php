<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * Offline applicability of *synthetic, human-reviewed* rules, not legal advice.
 * It never evaluates evidence of compliance or makes external requests.
 */
final class NormativeApplicability
{
    private const KINDS = ['OBLIGATORIO', 'CONDICIONAL', 'PROHIBIDO', 'RECOMENDADO'];
    private const RULE_KEYS = [
        'approved', 'conditions', 'effective_from', 'effective_until', 'kind',
        'reviewer_ref', 'source', 'tenant_ref', 'verified_on',
    ];
    private const SOURCE_KEYS = ['article', 'issuer', 'kind', 'official_url', 'version'];
    private const CONTEXT_KEYS = ['ciiu', 'municipio', 'regimen', 'riesgo', 'sector', 'tamano_empresa', 'tenant_ref'];
    private const CONDITION_KEYS = ['ciiu', 'municipio', 'regimen', 'riesgo', 'sector', 'tamano_empresa'];
    private const UNKNOWN_MARKERS = ['unknown', 'desconocido', 'pendiente', 'sin_dato', 'no_definido'];

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     * @return array{kind: string, applicability: string, explanation: string, source: array<string, mixed>|null}
     */
    public static function evaluate(array $rule, array $context, string $referenceDate): array
    {
        if (!self::hasExactKeys($rule, self::RULE_KEYS)
            || !self::hasExactKeys($context, self::CONTEXT_KEYS)
            || self::date($referenceDate) === null
        ) {
            return self::review('Contrato o fecha de evaluación desconocidos.');
        }

        foreach (self::CONTEXT_KEYS as $key) {
            if (!self::knownDimension($context[$key])) {
                return self::review('Perfil empresarial incompleto.');
            }
        }
        if (!self::identifier($rule['tenant_ref'])
            || $rule['tenant_ref'] !== $context['tenant_ref']
        ) {
            return self::review('La regla no está autorizada para este tenant.');
        }
        if ($rule['approved'] !== true
            || !self::identifier($rule['reviewer_ref'])
            || !in_array($rule['kind'], self::KINDS, true)
        ) {
            return self::review('Clasificación o revisión profesional no acreditadas.');
        }

        $source = $rule['source'];
        if (!is_array($source) || !self::hasExactKeys($source, self::SOURCE_KEYS)) {
            return self::review('Procedencia oficial incompleta.');
        }
        foreach (['article', 'issuer', 'version'] as $field) {
            if (!self::identifier($source[$field])) {
                return self::review('Procedencia oficial incompleta.');
            }
        }
        if ($source['kind'] !== 'official'
            || !is_string($source['official_url'])
            || preg_match('~^https://[a-z0-9.-]+/[a-zA-Z0-9/_?=.&%-]+$~iD', $source['official_url']) !== 1
        ) {
            return self::review('La fuente no está documentada como oficial.');
        }

        $start = self::date($rule['effective_from']);
        $until = self::date($rule['effective_until']);
        $verified = self::date($rule['verified_on']);
        $asOf = self::date($referenceDate);
        if ($start === null || $until === null || $verified === null
            || $start > $until || $asOf < $start || $asOf > $until || $verified > $asOf
        ) {
            return self::review('Vigencia o fecha de revisión no verificables.');
        }

        $conditions = $rule['conditions'];
        if (!is_array($conditions) || $conditions === [] || count($conditions) > 6) {
            return self::review('Condiciones de aplicabilidad incompletas.');
        }
        foreach ($conditions as $field => $expected) {
            if (!is_string($field) || !in_array($field, self::CONDITION_KEYS, true)
                || !self::knownDimension($expected)
            ) {
                return self::review('Condición no reconocida.');
            }
        }
        foreach ($conditions as $field => $expected) {
            if ($context[$field] !== $expected) {
                return [
                    'kind' => 'NO_APLICA',
                    'applicability' => 'NO_APLICA',
                    'explanation' => 'Condición explícita distinta en el perfil empresarial validado.',
                    'source' => $source,
                ];
            }
        }
        return [
            'kind' => $rule['kind'],
            'applicability' => 'APLICA',
            'explanation' => 'Regla revisada, dentro de su vigencia y aplicable al contexto indicado.',
            'source' => $source,
        ];
    }

    /** @return array{kind: string, applicability: string, explanation: string, source: null} */
    private static function review(string $reason): array
    {
        return [
            'kind' => 'REQUIERE_REVISION',
            'applicability' => 'DESCONOCIDO',
            'explanation' => $reason,
            'source' => null,
        ];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/iD', $value) === 1;
    }

    private static function knownDimension(mixed $value): bool
    {
        return self::identifier($value)
            && !in_array(strtolower($value), self::UNKNOWN_MARKERS, true);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value
            ? $parsed
            : null;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param list<string> $expected
     */
    private static function hasExactKeys(array $payload, array $expected): bool
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * Pure synthetic-only projection over F0. Never evaluates legal compliance.
 */
final class NormativeMatrix
{
    private const MAX_ROWS = 100;
    private const UNKNOWN = ['unknown', 'desconocido', 'pendiente', 'sin_dato', 'no_definido'];

    /**
     * @param array<array-key, mixed> $rules
     * @param array<string, mixed> $context
     * @return array{tenant_ref: ?string, municipio: ?string, rows: list<array<string, mixed>>}
     */
    public static function evaluate(array $rules, array $context, string $referenceDate): array
    {
        $tenant = $context['tenant_ref'] ?? null;
        if (!self::knownIdentifier($tenant)) {
            return ['tenant_ref' => null, 'municipio' => null, 'rows' => [self::review()]];
        }
        $municipio = $context['municipio'] ?? null;
        $scope = [
            'tenant_ref' => $tenant,
            'municipio' => self::knownIdentifier($municipio) ? $municipio : null,
        ];
        if (!array_is_list($rules) || count($rules) > self::MAX_ROWS) {
            return [...$scope, 'rows' => [self::review()]];
        }

        $rows = [];
        foreach ($rules as $candidate) {
            if (!is_array($candidate)) {
                $rows[] = self::review();
                continue;
            }
            $ruleTenant = $candidate['tenant_ref'] ?? null;
            if (!self::knownIdentifier($ruleTenant)) {
                $rows[] = self::review();
                continue;
            }
            if ($ruleTenant !== $tenant) {
                continue; // Never disclose foreign-tenant existence or provenance.
            }

            $outcome = NormativeApplicability::evaluate($candidate, $context, $referenceDate);
            $verified = is_array($outcome['source']);
            $rows[] = [
                'kind' => $outcome['kind'],
                'applicability' => $outcome['applicability'],
                'explanation' => $outcome['explanation'],
                'source' => $verified ? $outcome['source'] : null,
                'reviewer_ref' => $verified && is_string($candidate['reviewer_ref'] ?? null)
                    ? $candidate['reviewer_ref'] : null,
                'verified_on' => $verified && is_string($candidate['verified_on'] ?? null)
                    ? $candidate['verified_on'] : null,
                'effective_from' => $verified && is_string($candidate['effective_from'] ?? null)
                    ? $candidate['effective_from'] : null,
                'effective_until' => $verified && is_string($candidate['effective_until'] ?? null)
                    ? $candidate['effective_until'] : null,
            ];
        }

        return [...$scope, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private static function review(): array
    {
        return [
            'kind' => 'REQUIERE_REVISION',
            'applicability' => 'DESCONOCIDO',
            'explanation' => 'Regla o contexto sin ámbito verificable.',
            'source' => null,
            'reviewer_ref' => null,
            'verified_on' => null,
            'effective_from' => null,
            'effective_until' => null,
        ];
    }

    private static function knownIdentifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/iD', $value) === 1
            && !in_array(strtolower($value), self::UNKNOWN, true);
    }
}

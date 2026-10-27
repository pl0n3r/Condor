<?php

declare(strict_types=1);

namespace App\Application\Assets;

/**
 * Pure offline projection of synthetic asset lifecycle events.
 * Never reads a database, network, clock, identity or business record.
 */
final class AssetRegisterLifecycle
{
    private const MAX_ASSETS = 100;
    private const ACTIONS = ['ALTA', 'ASIGNAR', 'MANTENIMIENTO', 'SOLICITAR_BAJA'];

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function project(array $input): array
    {
        $invalid = self::invalid();
        if (!self::keys($input, ['tenant_ref', 'company_ref', 'as_of', 'assets'])
            || !self::tenant($input['tenant_ref'])
            || !self::ref($input['company_ref'], 'company')
            || !self::date($input['as_of'])
            || !is_array($input['assets'])
            || !array_is_list($input['assets'])
            || count($input['assets']) > self::MAX_ASSETS) {
            return $invalid;
        }
        $seen = [];
        $rows = [];
        foreach ($input['assets'] as $asset) {
            if (!is_array($asset)
                || !self::keys($asset, [
                    'tenant_ref', 'company_ref', 'asset_ref', 'responsible_ref',
                    'warranty_until', 'maintenance_on', 'action', 'evidence_ref',
                ])
                || !self::tenant($asset['tenant_ref'])
                || !self::ref($asset['company_ref'], 'company')
                || !self::ref($asset['asset_ref'], 'asset')
                || ($asset['responsible_ref'] !== null && !self::ref($asset['responsible_ref'], 'responsible'))
                || ($asset['warranty_until'] !== null && !self::date($asset['warranty_until']))
                || ($asset['maintenance_on'] !== null && !self::date($asset['maintenance_on']))
                || !in_array($asset['action'], self::ACTIONS, true)
                || ($asset['evidence_ref'] !== null && !self::ref($asset['evidence_ref'], 'evidence'))
                || ($asset['action'] === 'ASIGNAR' && $asset['responsible_ref'] === null)
                || ($asset['action'] === 'SOLICITAR_BAJA' && $asset['evidence_ref'] === null)) {
                return $invalid;
            }
            // Read only the requested tenant AND company. No record can cross scope.
            if ($asset['tenant_ref'] !== $input['tenant_ref']
                || $asset['company_ref'] !== $input['company_ref']) {
                continue;
            }
            if (isset($seen[$asset['asset_ref']])) {
                return $invalid;
            }
            $seen[$asset['asset_ref']] = true;
            $state = match ($asset['action']) {
                'ALTA' => 'INVENTARIADO',
                'ASIGNAR' => 'ASIGNADO',
                'MANTENIMIENTO' => 'MANTENIMIENTO_PENDIENTE',
                default => 'BAJA_REQUIERE_REVISION',
            };
            $rows[] = [
                'asset_ref' => $asset['asset_ref'],
                'responsible_ref' => $asset['responsible_ref'],
                'warranty_until' => $asset['warranty_until'],
                'warranty_expired' => $asset['warranty_until'] !== null
                    && strcmp($asset['warranty_until'], $input['as_of']) < 0,
                'maintenance_on' => $asset['maintenance_on'],
                'maintenance_due' => $asset['maintenance_on'] !== null
                    && strcmp($asset['maintenance_on'], $input['as_of']) <= 0,
                'state' => $state,
                'evidence_ref' => $asset['evidence_ref'],
                'can_approve' => false,
                'can_close' => false,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['asset_ref'], $b['asset_ref']));
        return [
            'valid' => true,
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
            'state' => 'REQUIERE_REVISION',
            'can_approve' => false,
            'can_close' => false,
            'assets' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private static function invalid(): array
    {
        return [
            'valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'state' => 'REQUIERE_REVISION', 'can_approve' => false, 'can_close' => false,
            'assets' => [],
        ];
    }

    /** @param array<array-key, mixed> $actual
     *  @param list<string> $expected
     */
    private static function keys(array $actual, array $expected): bool
    {
        $found = array_keys($actual);
        sort($found);
        sort($expected);
        return $found === $expected;
    }

    private static function tenant(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    private static function ref(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Documents;

/**
 * Pure, offline, synthetic-only projection of dossier version histories.
 * No content, persistence, legal approval, or I/O is handled here.
 */
final class DocumentDossierVersions
{
    private const MAX_DOSSIERS = 100;
    private const MAX_VERSIONS = 100;
    private const CLASSIFICATIONS = ['PUBLICA', 'INTERNA', 'RESERVADA'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function project(array $input): array
    {
        $invalid = self::invalid();
        if (!self::keys($input, ['tenant_ref', 'company_ref', 'as_of', 'dossiers'])
            || !self::tenant($input['tenant_ref'])
            || !self::ref($input['company_ref'], 'company')
            || !self::date($input['as_of'])
            || !is_array($input['dossiers'])
            || !array_is_list($input['dossiers'])
            || count($input['dossiers']) > self::MAX_DOSSIERS) {
            return $invalid;
        }

        $dossiers = [];
        $seen = [];
        foreach ($input['dossiers'] as $dossier) {
            if (!is_array($dossier)
                || !self::keys($dossier, [
                    'tenant_ref', 'company_ref', 'ref', 'entity_ref', 'classification', 'versions',
                ])
                || !self::tenant($dossier['tenant_ref'])
                || !self::ref($dossier['company_ref'], 'company')
                || !self::ref($dossier['ref'], 'dossier')
                || !self::ref($dossier['entity_ref'], 'entity')
                || !in_array($dossier['classification'], self::CLASSIFICATIONS, true)
                || !is_array($dossier['versions'])
                || !array_is_list($dossier['versions'])
                || count($dossier['versions']) > self::MAX_VERSIONS) {
                return $invalid;
            }
            $versions = [];
            $numbers = [];
            $refs = [];
            foreach ($dossier['versions'] as $version) {
                if (!is_array($version)
                    || !self::keys($version, ['ref', 'number', 'hash', 'previous_ref'])
                    || !self::ref($version['ref'], 'version')
                    || !is_int($version['number'])
                    || $version['number'] < 1
                    || $version['number'] > self::MAX_VERSIONS
                    || !self::syntheticHash($version['hash'])
                    || ($version['previous_ref'] !== null && !self::ref($version['previous_ref'], 'version'))
                    || isset($numbers[$version['number']])
                    || isset($refs[$version['ref']])) {
                    return $invalid;
                }
                $numbers[$version['number']] = true;
                $refs[$version['ref']] = true;
                $versions[] = $version;
            }
            usort($versions, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
            foreach ($versions as $i => $version) {
                if ($version['number'] !== $i + 1
                    || $version['previous_ref'] !== ($i === 0 ? null : $versions[$i - 1]['ref'])) {
                    return $invalid;
                }
            }
            if ($dossier['tenant_ref'] !== $input['tenant_ref']
                || $dossier['company_ref'] !== $input['company_ref']) {
                continue; // Foreign records cannot enter this tenant/company projection.
            }
            if (isset($seen[$dossier['ref']])) {
                return $invalid;
            }
            $seen[$dossier['ref']] = true;
            $dossiers[] = [
                'ref' => $dossier['ref'],
                'entity_ref' => $dossier['entity_ref'],
                'classification' => $dossier['classification'],
                'state' => 'REQUIERE_REVISION',
                'can_approve' => false,
                'can_close' => false,
                'versions' => $versions,
            ];
        }
        usort($dossiers, static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));

        return [
            'valid' => true,
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
            'state' => 'REQUIERE_REVISION',
            'can_approve' => false,
            'can_close' => false,
            'dossiers' => $dossiers,
        ];
    }

    /** @return array<string, mixed> */
    private static function invalid(): array
    {
        return [
            'valid' => false, 'tenant_ref' => null, 'company_ref' => null,
            'state' => 'REQUIERE_REVISION', 'can_approve' => false, 'can_close' => false,
            'dossiers' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expected
     */
    private static function keys(array $data, array $expected): bool
    {
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private static function tenant(mixed $value): bool
    {
        return is_string($value) && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    private static function ref(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function syntheticHash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('~^fixture:sha256/[a-f0-9]{64}$~D', $value) === 1;
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

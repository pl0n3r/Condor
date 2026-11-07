<?php

declare(strict_types=1);

namespace App\Application\SocialSecurity;

use InvalidArgumentException;

/**
 * M05 S02-S06: ephemeral, deterministic previews only. No database, network, payments,
 * notifications, PILA submissions, real identities, or legal calculations.
 */
final class SocialSecuritySyntheticSlicesService
{
    private const CONTRIBUTION_SLICES = ['novelties', 'contributions'];
    private const PILA_SLICES = ['supports', 'alerts', 'pila'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(string $slice, array $input, string $tenantId, string $companyId): array
    {
        if (!in_array($slice, [...self::CONTRIBUTION_SLICES, ...self::PILA_SLICES], true)) {
            throw new InvalidArgumentException('Unsupported synthetic slice');
        }
        $contributions = in_array($slice, self::CONTRIBUTION_SLICES, true);
        $keys = $contributions
            ? ['company_id', 'policy', 'affiliations', 'novelties']
            : ['company_id', 'reference_date', 'supports', 'alerts'];
        self::exactKeys($input, $keys);
        if (($input['company_id'] ?? null) !== $companyId) {
            throw new InvalidArgumentException('Company scope mismatch');
        }

        // Never trust client-supplied tenant references or real subject identifiers.
        // Different company/tenant scopes use different opaque fixture tokens.
        $tenant = 'fixture:tenant/t'.substr(hash('sha256', $tenantId.'|'.$companyId), 0, 20);
        if ($contributions) {
            if (!is_array($input['policy'])) {
                throw new InvalidArgumentException('Policy fixture required');
            }
            $result = SocialSecurityContributionsProjection::project([
                'tenant_ref' => $tenant,
                'policy' => $input['policy'],
                'affiliations' => $this->fixtureRows($input['affiliations'], [
                    'employee_ref', 'affiliation_ref', 'base_units',
                ], $tenant),
                'novelties' => $this->fixtureRows($input['novelties'], [
                    'employee_ref', 'novelty_ref',
                ], $tenant),
            ]);
            $rows = array_map(static function (array $row) use ($slice): array {
                return $slice === 'novelties'
                    ? ['employee_ref' => $row['employee_ref'], 'novelties' => $row['novelties']]
                    : [
                        'employee_ref' => $row['employee_ref'],
                        'health_units' => $row['health_units'],
                        'pension_units' => $row['pension_units'],
                    ];
            }, $result['rows']);
            $data = ['rows' => $rows, 'policy_version' => $result['policy_version']];
        } else {
            $result = SocialSecurityPilaPreparation::project([
                'tenant_ref' => $tenant,
                'reference_date' => $input['reference_date'],
                'supports' => $this->fixtureRows($input['supports'], [
                    'employee_ref', 'support_ref',
                ], $tenant),
                'alerts' => $this->fixtureRows($input['alerts'], [
                    'employee_ref', 'alert_ref', 'due_on',
                ], $tenant),
            ]);
            $data = match ($slice) {
                'supports' => ['supports' => $result['supports']],
                'alerts' => ['alerts' => $result['alerts']],
                'pila' => [
                    'pila_example' => [
                        'fixture_ref' => $result['pila_example']['fixture_ref'],
                        'state' => 'BORRADOR_NO_ENVIABLE',
                        'support_count' => $result['pila_example']['support_count'],
                        'can_send' => false,
                    ],
                ],
            };
        }

        return [
            'slice' => $slice,
            'synthetic' => true,
            'persisted' => false,
            'legal_value' => false,
            'can_send' => false,
            'notifications_sent' => false,
            'payments_made' => false,
            'state' => 'REQUIERE_REVISION',
            ...$data,
        ];
    }

    /**
     * @param mixed $input
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(mixed $input, array $fields, string $tenant): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 100) {
            throw new InvalidArgumentException('Invalid fixture collection');
        }
        $rows = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid fixture row');
            }
            self::exactKeys($row, $fields);
            $rows[] = ['tenant_ref' => $tenant, ...$row];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expected
     */
    private static function exactKeys(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Unexpected or missing fixture fields');
        }
    }
}

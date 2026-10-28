<?php

declare(strict_types=1);

namespace App\Application\Commercial;

/**
 * #1073: offline-only proof of a synthetic tenant export and offboarding plan.
 *
 * The caller must supply *all* four synthetic datasets. Nothing here touches
 * Doctrine, queues, payments, storage, providers, or actual deletion.
 * Commercial facts are validated by the existing OnboardingChecklist; prices
 * and entitlements are never redefined by this projection.
 */
final class TenantOffboardingExport
{
    private const DATASETS = ['audit', 'catalog', 'inventory', 'operations'];

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public static function evaluate(array $input): array
    {
        $rejected = self::rejected();
        if (!self::keys($input, [
            'tenant_ref', 'company_ref', 'actor_ref', 'actor_role',
            'request_ref', 'requested_on', 'retention_until', 'action',
            'commercial', 'datasets',
        ])) {
            return $rejected;
        }

        $tenant = $input['tenant_ref'];
        $company = $input['company_ref'];
        $actor = $input['actor_ref'];
        $request = $input['request_ref'];
        $role = $input['actor_role'];
        $action = $input['action'];
        if (!self::ref($tenant, 'tenant')
            || !self::ref($company, 'company')
            || !self::ref($actor, 'actor')
            || !self::ref($request, 'request')
            || !in_array($role, ['tenant_admin', 'platform_admin'], true)
            || !in_array($action, ['export_preview', 'offboarding_review'], true)
            || ($action === 'offboarding_review' && $role !== 'platform_admin')
            || !self::date($input['requested_on'])
            || !self::date($input['retention_until'])
            || strcmp($input['retention_until'], $input['requested_on']) <= 0
        ) {
            return $rejected;
        }

        $commercial = $input['commercial'];
        if (!is_array($commercial)
            || !self::keys($commercial, [
                'tenant_ref', 'company_ref', 'actor_ref', 'actor_role',
                'catalog', 'options', 'entitlements', 'subscription', 'events',
            ])
            || $commercial['tenant_ref'] !== $tenant
            || $commercial['company_ref'] !== $company
            || $commercial['actor_ref'] !== $actor
            || $commercial['actor_role'] !== $role
            || !class_exists(OnboardingChecklist::class)
            || !OnboardingChecklist::evaluate($commercial)['valid']
        ) {
            return $rejected;
        }

        $datasets = $input['datasets'];
        if (!is_array($datasets) || !array_is_list($datasets)
            || count($datasets) !== count(self::DATASETS)) {
            return $rejected;
        }

        $manifest = [];
        $seen = [];
        foreach ($datasets as $dataset) {
            if (!is_array($dataset)
                || !self::keys($dataset, [
                    'key', 'tenant_ref', 'company_ref', 'records',
                ])
                || !in_array($dataset['key'], self::DATASETS, true)
                || isset($seen[$dataset['key']])
                || $dataset['tenant_ref'] !== $tenant
                || $dataset['company_ref'] !== $company
                || !is_array($dataset['records'])
                || !array_is_list($dataset['records'])
                || count($dataset['records']) > 500
            ) {
                return $rejected;
            }

            $refs = [];
            foreach ($dataset['records'] as $record) {
                if (!is_array($record)
                    || !self::keys($record, [
                        'tenant_ref', 'company_ref', 'record_ref',
                    ])
                    || $record['tenant_ref'] !== $tenant
                    || $record['company_ref'] !== $company
                    || !self::ref($record['record_ref'], 'record')
                    || isset($refs[$record['record_ref']])
                ) {
                    return $rejected;
                }
                $refs[$record['record_ref']] = true;
            }
            $recordRefs = array_keys($refs);
            sort($recordRefs, SORT_STRING);
            $manifest[$dataset['key']] = [
                'count' => count($recordRefs),
                'sha256' => hash('sha256', json_encode(
                    [$tenant, $company, $dataset['key'], $recordRefs],
                    JSON_THROW_ON_ERROR,
                )),
            ];
            $seen[$dataset['key']] = true;
        }
        ksort($manifest, SORT_STRING);
        if (array_keys($manifest) !== self::DATASETS) {
            return $rejected;
        }

        // Read-only evidence of what was *supplied*, not a database backup.
        $digest = hash('sha256', json_encode(
            [$tenant, $company, $request, $manifest],
            JSON_THROW_ON_ERROR,
        ));
        $isOffboarding = $action === 'offboarding_review';

        return [
            'valid' => true,
            'tenant_ref' => $tenant,
            'company_ref' => $company,
            'state' => $isOffboarding ? 'BAJA_PENDIENTE_REVISION' : 'EXPORTACION_PENDIENTE_REVISION',
            'manifest' => $manifest,
            'digest' => $digest,
            'retention_until' => $input['retention_until'],
            'audit' => [[
                'tenant_ref' => $tenant,
                'company_ref' => $company,
                'actor_ref' => $actor,
                'request_ref' => $request,
                'action' => $isOffboarding ? 'BAJA_SOLICITADA' : 'EXPORTACION_PREPARADA',
                'on' => $input['requested_on'],
                'outcome' => 'PENDIENTE_REVISION_MANUAL',
            ]],
            'manual_review_required' => true,
            'deletion_not_before' => $isOffboarding ? $input['retention_until'] : null,
            'deletion_scheduled' => false,
            'can_delete' => false,
            'can_charge' => false,
        ];
    }

    /** @param array<string,mixed> $input */
    public static function verify(array $input, string $expectedDigest): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1) {
            return false;
        }
        $result = self::evaluate($input);
        return $result['valid'] === true
            && is_string($result['digest'])
            && hash_equals($expectedDigest, $result['digest']);
    }

    /** @return array<string,mixed> */
    private static function rejected(): array
    {
        return [
            'valid' => false,
            'tenant_ref' => null,
            'company_ref' => null,
            'state' => 'REQUIERE_REVISION',
            'manifest' => [],
            'digest' => null,
            'retention_until' => null,
            'audit' => [],
            'manual_review_required' => true,
            'deletion_not_before' => null,
            'deletion_scheduled' => false,
            'can_delete' => false,
            'can_charge' => false,
        ];
    }

    private static function ref(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match(
                '~\Afixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}\z~D',
                $value,
            ) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    /** @param array<string,mixed> $row
     *  @param list<string> $keys
     */
    private static function keys(array $row, array $keys): bool
    {
        $actual = array_keys($row);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}

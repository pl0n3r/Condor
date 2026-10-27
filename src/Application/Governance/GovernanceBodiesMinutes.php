<?php

declare(strict_types=1);

namespace App\Application\Governance;

/**
 * Synthetic-only, deterministic corporate governance preview.
 * Not a legal determination or an approval engine; no storage or external I/O.
 */
final class GovernanceBodiesMinutes
{
    private const LIMIT = 100;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function project(array $input): array
    {
        $invalid = [
            'valid' => false,
            'state' => 'REQUIERE_REVISION',
            'tenant_ref' => null,
            'company_ref' => null,
            'bodies' => [],
            'minutes' => [],
            'powers' => [],
            'error' => 'invalid_synthetic_fixture',
        ];
        if (!self::keys($input, ['as_of', 'bodies', 'company_ref', 'minutes', 'powers', 'tenant_ref'])
            || !self::tenant($input['tenant_ref'])
            || !self::fixture($input['company_ref'], 'company')
            || !self::date($input['as_of'])
            || !self::rows($input['bodies'])
            || !self::rows($input['minutes'])
            || !self::rows($input['powers'])
        ) {
            return $invalid;
        }

        $seen = [];
        $bodies = [];
        $representatives = [];
        foreach ($input['bodies'] as $body) {
            if (!is_array($body)
                || !self::keys($body, ['kind', 'ref', 'representative_ref'])
                || !self::fixture($body['ref'], 'body')
                || !self::fixture($body['representative_ref'], 'representative')
                || !in_array($body['kind'], ['assembly', 'board', 'committee'], true)
                || isset($seen[$body['ref']])
            ) {
                return $invalid;
            }
            $seen[$body['ref']] = true;
            $representatives[$body['representative_ref']] = true;
            $bodies[] = [
                'ref' => $body['ref'],
                'kind' => $body['kind'],
                'representative_ref' => $body['representative_ref'],
                'state' => 'REQUIERE_REVISION',
            ];
        }

        $seenMinutes = [];
        $minutes = [];
        foreach ($input['minutes'] as $minute) {
            if (!is_array($minute)
                || !self::keys($minute, ['body_ref', 'members_present', 'members_total', 'recorded_on', 'ref'])
                || !self::fixture($minute['ref'], 'minute')
                || !self::fixture($minute['body_ref'], 'body')
                || !isset($seen[$minute['body_ref']])
                || !self::date($minute['recorded_on'])
                || !self::countMembers($minute['members_present'], $minute['members_total'])
                || isset($seenMinutes[$minute['ref']])
            ) {
                return $invalid;
            }
            $seenMinutes[$minute['ref']] = true;
            $minutes[] = [
                'ref' => $minute['ref'],
                'body_ref' => $minute['body_ref'],
                'recorded_on' => $minute['recorded_on'],
                'members_present' => $minute['members_present'],
                'members_total' => $minute['members_total'],
                'quorum_requires_review' => true,
                'state' => 'REQUIERE_REVISION',
            ];
        }

        $seenPowers = [];
        $powers = [];
        foreach ($input['powers'] as $power) {
            if (!is_array($power)
                || !self::keys($power, ['ref', 'representative_ref', 'valid_from', 'valid_until'])
                || !self::fixture($power['ref'], 'power')
                || !self::fixture($power['representative_ref'], 'representative')
                || !isset($representatives[$power['representative_ref']])
                || !self::date($power['valid_from'])
                || !self::date($power['valid_until'])
                || $power['valid_from'] > $power['valid_until']
                || isset($seenPowers[$power['ref']])
            ) {
                return $invalid;
            }
            $seenPowers[$power['ref']] = true;
            $powers[] = [
                'ref' => $power['ref'],
                'representative_ref' => $power['representative_ref'],
                'valid_from' => $power['valid_from'],
                'valid_until' => $power['valid_until'],
                'within_date_window' => $power['valid_from'] <= $input['as_of']
                    && $input['as_of'] <= $power['valid_until'],
                'state' => 'REQUIERE_REVISION',
            ];
        }
        $byFixtureRef = static fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']);
        usort($bodies, $byFixtureRef);
        usort($minutes, $byFixtureRef);
        usort($powers, $byFixtureRef);

        return [
            'valid' => true,
            'state' => 'REQUIERE_REVISION',
            'tenant_ref' => $input['tenant_ref'],
            'company_ref' => $input['company_ref'],
            'bodies' => $bodies,
            'minutes' => $minutes,
            'powers' => $powers,
            'error' => null,
        ];
    }

    private static function tenant(mixed $value): bool
    {
        return is_string($value) && preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $value) === 1;
    }

    private static function fixture(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    private static function countMembers(mixed $present, mixed $total): bool
    {
        return is_int($present) && is_int($total)
            && $present >= 0 && $total > 0 && $total <= self::LIMIT && $present <= $total;
    }

    private static function rows(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= self::LIMIT;
    }

    /** @param array<array-key,mixed> $value @param list<string> $expected */
    private static function keys(array $value, array $expected): bool
    {
        if (count($value) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }
}

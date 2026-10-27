<?php
declare(strict_types=1);

namespace App\Application\SocialSecurity;

use DateTimeImmutable;
use InvalidArgumentException;

/** Synthetic-only PILA preparation: never a real filing or submission. */
final class SocialSecurityPilaPreparation
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function project(array $input): array
    {
        self::keys($input, ['tenant_ref', 'reference_date', 'supports', 'alerts']);
        $tenant = self::ref($input['tenant_ref'] ?? null, 'tenant');
        $today = self::date($input['reference_date'] ?? null);
        foreach (['supports', 'alerts'] as $collection) {
            if (!isset($input[$collection]) || !is_array($input[$collection])
                || !array_is_list($input[$collection]) || count($input[$collection]) > 100) {
                throw new InvalidArgumentException('Invalid fixture collection');
            }
        }
        $supports = [];
        $seen = [];
        foreach ($input['supports'] as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid fixture support');
            }
            self::keys($item, ['tenant_ref', 'employee_ref', 'support_ref']);
            $scope = self::ref($item['tenant_ref'] ?? null, 'tenant');
            $employee = self::ref($item['employee_ref'] ?? null, 'employee');
            $ref = self::ref($item['support_ref'] ?? null, 'support');
            if ($scope !== $tenant) {
                continue;
            }
            if (isset($seen[$ref])) {
                throw new InvalidArgumentException('Duplicate support');
            }
            $seen[$ref] = true;
            $supports[] = ['employee_ref' => $employee, 'support_ref' => $ref, 'state' => 'REQUIERE_REVISION'];
        }
        $alerts = [];
        $seen = [];
        foreach ($input['alerts'] as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid fixture alert');
            }
            self::keys($item, ['tenant_ref', 'employee_ref', 'alert_ref', 'due_on']);
            $scope = self::ref($item['tenant_ref'] ?? null, 'tenant');
            $employee = self::ref($item['employee_ref'] ?? null, 'employee');
            $ref = self::ref($item['alert_ref'] ?? null, 'alert');
            $due = self::date($item['due_on'] ?? null);
            if ($scope !== $tenant) {
                continue;
            }
            if (isset($seen[$ref])) {
                throw new InvalidArgumentException('Duplicate alert');
            }
            $seen[$ref] = true;
            $alerts[] = [
                'employee_ref' => $employee, 'alert_ref' => $ref,
                'due_on' => $due, 'attention_candidate' => $due <= $today,
                'state' => 'EN_REVISION',
            ];
        }
        usort($supports, static fn (array $a, array $b): int => strcmp($a['support_ref'], $b['support_ref']));
        usort($alerts, static fn (array $a, array $b): int => strcmp($a['alert_ref'], $b['alert_ref']));
        return [
            'tenant_ref' => $tenant,
            'reference_date' => $today,
            'supports' => $supports,
            'alerts' => $alerts,
            'pila_example' => [
                'fixture_ref' => 'fixture:pila/draft',
                'state' => 'BORRADOR_NO_ENVIABLE',
                'tenant_ref' => $tenant,
                'support_count' => count($supports),
            ],
            'legal_value' => false,
            'can_send' => false,
            'state' => 'REQUIERE_REVISION',
        ];
    }

    /** @param array<string,mixed> $data @param list<string> $required */
    private static function keys(array $data, array $required): void
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($required, SORT_STRING);
        if ($keys !== $required) {
            throw new InvalidArgumentException('Non-fixture fields or incomplete fixture');
        }
    }

    private static function ref(mixed $value, string $kind): string
    {
        if (!is_string($value) || preg_match('~^fixture:' . $kind . '/[a-z][a-z0-9_-]{0,47}$~D', $value) !== 1) {
            throw new InvalidArgumentException('Expected synthetic fixture reference');
        }
        return $value;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new InvalidArgumentException('Invalid fixture date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date');
        }
        return $value;
    }
}

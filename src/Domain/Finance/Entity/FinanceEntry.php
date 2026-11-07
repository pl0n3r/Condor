<?php

declare(strict_types=1);

namespace App\Domain\Finance\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Audited, company-scoped financial case. No money movement or live posting. */
final class FinanceEntry
{
    private const TYPES = ['ingreso' => true, 'egreso' => true, 'ajuste' => true, 'presupuesto' => true];
    private const STATES = ['PENDIENTE', 'REQUIERE_REVISION'];

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        foreach ([$tenant, $company, $actor] as $value) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $value) !== 1) {
                throw new DomainException('Identidad financiera inválida.');
            }
        }
    }

    public static function assertReference(string $ref): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) !== 1
            || preg_match('/[0-9]{7,}/', $ref) === 1) {
            throw new DomainException('Referencia financiera inválida o identificable.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!isset(self::TYPES[$kind])) {
            throw new DomainException('Tipo financiero no admitido.');
        }
        return [
            'id' => UlidFactory::new(), 'tenant_id' => $tenant, 'company_id' => $company,
            'entry_ref' => $ref, 'kind' => $kind, 'state' => self::STATES[0],
            'version' => 1, 'previous_id' => null, 'actor_id' => $actor,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function inspect(array $row, string $tenant, string $company): array
    {
        if (($row['tenant_id'] ?? null) !== $tenant || ($row['company_id'] ?? null) !== $company) {
            throw new DomainException('Registro financiero ajeno al ámbito.');
        }
        self::assertIdentity($tenant, $company, (string) ($row['actor_id'] ?? ''));
        self::assertReference((string) ($row['entry_ref'] ?? ''));
        if (!isset(self::TYPES[(string) ($row['kind'] ?? '')])
            || !in_array($row['state'] ?? null, self::STATES, true)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', (string) ($row['id'] ?? '')) !== 1
            || !is_numeric($row['version'] ?? null)
            || (int) $row['version'] < 1 || (int) $row['version'] > 1000000
            || (($row['previous_id'] ?? null) !== null
                && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', (string) $row['previous_id']) !== 1)) {
            throw new DomainException('Historial financiero inválido.');
        }
        $row['version'] = (int) $row['version'];
        return $row;
    }

    /** @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    public static function revise(
        array $old, string $tenant, string $company, string $actor, string $next, int $version,
    ): array {
        $current = self::inspect($old, $tenant, $company);
        self::assertIdentity($tenant, $company, $actor);
        if ($current['version'] !== $version || $version >= 1000000
            || $next === $current['state'] || !in_array($next, self::STATES, true)) {
            throw new DomainException('Cambio financiero inválido o versión obsoleta.');
        }
        return [
            ...$current, 'id' => UlidFactory::new(), 'state' => $next,
            'version' => $version + 1, 'previous_id' => $current['id'], 'actor_id' => $actor,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];
    }
}

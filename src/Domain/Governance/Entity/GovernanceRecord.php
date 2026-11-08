<?php

declare(strict_types=1);

namespace App\Domain\Governance\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * M15 corporate-governance instrument. A synthetic, immutable revision, not a
 * juridical instrument or a completed authorization.
 */
final class GovernanceRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'REQUIERE_REVISION';

    private function __construct(
        private string $entryId,
        private string $tenantRef,
        private string $companyRef,
        private string $governanceRef,
        private string $category,
        private string $state,
        private int $revision,
        private ?string $priorId,
        private string $actorRef,
        private string $action,
        private DateTimeImmutable $recordedAt,
    ) {
    }

    /** Only typed fixture references can enter the persistence boundary. */
    public static function register(
        string $tenant, string $company, string $document,
        string $instrument, string $actor,
    ): self {
        self::verifyScope($tenant, $company, $actor);
        self::checkFixture($document, 'governance');
        if (!in_array($instrument, ['acta', 'organo', 'representante', 'socio', 'facultad', 'autorizacion', 'decision'], true)) {
            throw new DomainException('Tipo de instrumento sintético inválido.');
        }

        return new self(
            UlidFactory::new(), $tenant, $company, $document, $instrument,
            self::PENDING, 1, null, $actor, 'CREADA',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /** Never closes or approves an instrument. A human must authorize every revision. */
    public function requestState(string $next, string $actor, bool $humanApproved): self
    {
        self::checkFixture($actor, 'actor');
        if (!$humanApproved || $next === $this->state
            || !in_array($next, [self::PENDING, self::REVIEW], true)
            || $this->revision >= 2147483647
        ) {
            throw new DomainException('La revisión de gobierno requiere una decisión humana explícita.');
        }

        return new self(
            UlidFactory::new(), $this->tenantRef, $this->companyRef,
            $this->governanceRef, $this->category, $next,
            $this->revision + 1, $this->entryId, $actor, 'CAMBIO_EXPLICITO',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public static function verifyScope(string $tenant, string $company, string $actor): void
    {
        self::checkFixture($tenant, 'tenant');
        self::checkFixture($company, 'company');
        self::checkFixture($actor, 'actor');
    }

    public static function checkFixture(string $candidate, string $kind): void
    {
        // Only an explicitly typed synthetic prefix can cross this boundary.
        $types = ['tenant' => true, 'company' => true, 'actor' => true, 'governance' => true];
        $prefix = 'fixture:'.$kind.'/';
        if (!isset($types[$kind]) || !str_starts_with($candidate, $prefix)) {
            throw new DomainException('Solo se admiten referencias ficticias tipadas.');
        }
        $identifier = substr($candidate, strlen($prefix));
        if (preg_match('~\A[a-z][a-z0-9_-]{0,47}\z~D', $identifier) !== 1
            || preg_match('/[0-9]{7,}/', $identifier) === 1
        ) {
            throw new DomainException('Solo se admiten referencias ficticias tipadas.');
        }
    }

    public function id(): string { return $this->entryId; }
    public function tenant(): string { return $this->tenantRef; }
    public function company(): string { return $this->companyRef; }
    public function instrument(): string { return $this->governanceRef; }
    public function author(): string { return $this->actorRef; }
    public function state(): string { return $this->state; }
    public function revision(): int { return $this->revision; }
    public function prior(): ?string { return $this->priorId; }

    /** @return array<string,string|int|null> */
    public function toRow(): array
    {
        // Explicit column order is shared by DBAL writes and the integrity digest.
        $columns = [
            ['entry_id', $this->entryId],
            ['tenant_ref', $this->tenantRef],
            ['company_ref', $this->companyRef],
            ['governance_ref', $this->governanceRef],
            ['category', $this->category],
            ['state', $this->state],
            ['revision', $this->revision],
            ['prior_id', $this->priorId],
            ['actor_ref', $this->actorRef],
            ['action', $this->action],
            ['recorded_at', $this->recordedAt->format('Y-m-d H:i:s')],
        ];
        /** @var array<string, string|int|null> $row */
        $row = [];
        foreach ($columns as [$name, $value]) {
            $row[$name] = $value;
        }
        $row['fingerprint'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        return $row;
    }
    private const KINDS = ['ACTA', 'ACUERDO', 'POLITICA', 'REVISION'];
    private const STATES = ['PENDIENTE', 'EN_REVISION', 'REQUIERE_REVISION'];
    private const TRANSITIONS = [
        'PENDIENTE' => ['EN_REVISION', 'REQUIERE_REVISION'],
        'EN_REVISION' => ['REQUIERE_REVISION'],
        'REQUIERE_REVISION' => ['EN_REVISION'],
    ];

    private static function isUlid(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $id) === 1;
    }

    public static function assertIdentity(string $tenant, string $company, string $actor): void
    {
        foreach ([$tenant, $company, $actor] as $id) {
            if (!self::isUlid($id)) {
                throw new DomainException('Identidad Gobierno corporativo inválida.');
            }
        }
    }

    public static function assertReference(string $ref): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $ref) !== 1
            || preg_match('/\d{7,}/', $ref) === 1) {
            throw new DomainException('Solo se aceptan referencias Gobierno corporativo opacas.');
        }
    }

    /** @return array<string,mixed> */
    public static function begin(string $tenant, string $company, string $actor, string $ref, string $kind): array
    {
        self::assertIdentity($tenant, $company, $actor);
        self::assertReference($ref);
        if (!in_array($kind, self::KINDS, true)) {
            throw new DomainException('Categoría de revisión Gobierno corporativo no admitida.');
        }

        // Los identificadores se componen de atributos ya validados; no se
        // persiste contenido de la evidencia ni se presume su aprobación legal.
        $identity = [
            'tenant_id' => $tenant,
            'company_id' => $company,
            'actor_id' => $actor,
        ];
        $revision = [
            'record_ref' => $ref,
            'kind' => $kind,
            'state' => 'PENDIENTE',
            'version' => 1,
        ];
        return ['id' => UlidFactory::new(), ...$identity, ...$revision,
            'previous_id' => null, 'occurred_at' => gmdate('Y-m-d H:i:s')];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function inspect(array $record, string $tenant, string $company): array
    {
        foreach (['tenant_id' => $tenant, 'company_id' => $company] as $field => $expected) {
            if (($record[$field] ?? null) !== $expected) {
                throw new DomainException('Registro Gobierno corporativo fuera de ámbito.');
            }
        }
        if (!self::isUlid($record['id'] ?? null) || !self::isUlid($record['actor_id'] ?? null)) {
            throw new DomainException('Identidad de auditoría Gobierno corporativo inválida.');
        }
        self::assertReference((string) ($record['record_ref'] ?? ''));
        if (!in_array($record['kind'] ?? null, self::KINDS, true)
            || !in_array($record['state'] ?? null, self::STATES, true)) {
            throw new DomainException('Categoría o estado Gobierno corporativo no permitido.');
        }
        $raw = $record['version'] ?? null;
        $number = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($number === false || (string) $number !== (string) $raw) {
            throw new DomainException('Versión Gobierno corporativo inválida.');
        }
        $previous = $record['previous_id'] ?? null;
        if ($previous !== null && !self::isUlid($previous)) {
            throw new DomainException('Cadena de auditoría Gobierno corporativo inválida.');
        }

        $record['version'] = $number;
        return $record;
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
            || !in_array($next, self::TRANSITIONS[$current['state']] ?? [], true)) {
            throw new DomainException('Transición Gobierno corporativo inválida o versión obsoleta.');
        }

        return array_replace($current, [
            'id' => UlidFactory::new(),
            'state' => $next,
            'version' => $version + 1,
            'previous_id' => $current['id'],
            'actor_id' => $actor,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

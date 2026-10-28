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
        if (!in_array($instrument, ['acta', 'organo', 'representante', 'facultad', 'decision'], true)) {
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
        if (!in_array($kind, ['tenant', 'company', 'actor', 'governance'], true)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $candidate) !== 1
            || preg_match('/[0-9]{7,}/', $candidate) === 1
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
        $attributes = [
            'entry_id' => $this->entryId,
            'tenant_ref' => $this->tenantRef,
            'company_ref' => $this->companyRef,
            'governance_ref' => $this->governanceRef,
            'category' => $this->category,
            'state' => $this->state,
            'revision' => $this->revision,
            'prior_id' => $this->priorId,
            'actor_ref' => $this->actorRef,
            'action' => $this->action,
            'recorded_at' => $this->recordedAt->format('Y-m-d H:i:s'),
        ];
        return $attributes + [
            'fingerprint' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
        ];
    }
}

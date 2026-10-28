<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Synthetic-only M03 occupational state, segregated by tenant and occupational reference. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_occupational_record')]
final class OccupationalRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'EN_REVISION';
    public const CLOSURE_REVIEW = 'CIERRE_REQUIERE_REVISION';

    #[ORM\Id]
    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 80)]
    private string $tenantRef;

    #[ORM\Id]
    #[ORM\Column(name: 'record_ref', type: 'string', length: 80)]
    private string $recordRef;

    #[ORM\Column(name: 'actor_ref', type: 'string', length: 80)]
    private string $actorRef;

    #[ORM\Column(name: 'state', type: 'string', length: 32)]
    private string $state = self::PENDING;

    #[ORM\Column(name: 'evidence_ref', type: 'string', length: 80, nullable: true)]
    private ?string $evidenceRef = null;

    #[ORM\Column(name: 'review_due', type: 'string', length: 10, nullable: true)]
    private ?string $reviewDue = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $tenantRef,
        string $recordRef,
        string $actorRef,
        bool $authorized,
        ?string $reviewDue = null,
    ) {
        self::assertAuthority($tenantRef, $actorRef, $authorized);
        self::assertOccupationalRef($recordRef);
        if ($reviewDue !== null && !self::date($reviewDue)) {
            throw new DomainException('Fecha de revisión sintética inválida.');
        }
        $this->tenantRef = $tenantRef;
        $this->recordRef = $recordRef;
        $this->actorRef = $actorRef;
        $this->reviewDue = $reviewDue;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public static function assertAuthority(string $tenantRef, string $actorRef, bool $authorized): void
    {
        if (!$authorized
            || preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $tenantRef) !== 1
            || preg_match('~^fixture:actor/[a-z][a-z0-9_-]{0,47}$~D', $actorRef) !== 1) {
            throw new DomainException('Acceso no autorizado o referencias no sintéticas.');
        }
    }

    public static function assertOccupationalRef(string $recordRef): void
    {
        if (preg_match('~^fixture:occupational/[a-z][a-z0-9_-]{0,47}$~D', $recordRef) !== 1) {
            throw new DomainException('Referencia de activo no sintética.');
        }
    }

    public function transition(string $state, string $actorRef, bool $authorized, ?string $evidenceRef = null): void
    {
        self::assertAuthority($this->tenantRef, $actorRef, $authorized);
        if (!in_array($state, [self::REVIEW, self::CLOSURE_REVIEW], true)
            || ($state === self::CLOSURE_REVIEW
                && ($evidenceRef === null
                    || preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $evidenceRef) !== 1))
            || ($evidenceRef !== null
                && preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $evidenceRef) !== 1)) {
            throw new DomainException('El cierre automático está prohibido; transición o evidencia inválida.');
        }
        $this->state = $state;
        $this->actorRef = $actorRef;
        $this->evidenceRef = $evidenceRef;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function tenantRef(): string { return $this->tenantRef; }
    public function recordRef(): string { return $this->recordRef; }
    public function state(): string { return $this->state; }
    public function actorRef(): string { return $this->actorRef; }

    /** @return array<string, string|null> */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'record_ref' => $this->recordRef,
            'actor_ref' => $this->actorRef,
            'state' => $this->state,
            'evidence_ref' => $this->evidenceRef,
            'review_due' => $this->reviewDue,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    private static function date(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}

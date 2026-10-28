<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/**
 * M03 construction-only occupational safety record.
 * These IDs are fixtures, never real employees, accidents or legal findings.
 */
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
    private ?string $reviewDue;

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
        self::assertScope($tenantRef, $actorRef, $authorized);
        self::assertReference($recordRef);
        if ($reviewDue !== null && !self::validDate($reviewDue)) {
            throw new DomainException('Fecha sintética de revisión inválida.');
        }

        $this->tenantRef = $tenantRef;
        $this->recordRef = $recordRef;
        $this->actorRef = $actorRef;
        $this->reviewDue = $reviewDue;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public static function assertScope(string $tenantRef, string $actorRef, bool $authorized): void
    {
        $validTenant = preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $tenantRef) === 1;
        $validActor = preg_match('~^fixture:actor/[a-z][a-z0-9_-]{0,47}$~D', $actorRef) === 1;
        if (!$authorized || !$validTenant || !$validActor) {
            throw new DomainException('Operador o empresa no autorizados.');
        }
    }

    public static function assertReference(string $recordRef): void
    {
        if (preg_match('~^fixture:occupational/[a-z][a-z0-9_-]{0,47}$~D', $recordRef) !== 1) {
            throw new DomainException('Solo se aceptan registros ficticios SG-SST.');
        }
    }

    public function transition(
        string $nextState,
        string $actorRef,
        bool $authorized,
        ?string $evidenceRef = null,
    ): void {
        self::assertScope($this->tenantRef, $actorRef, $authorized);

        if (!in_array($nextState, [self::REVIEW, self::CLOSURE_REVIEW], true)) {
            throw new DomainException('No existen aprobaciones ni cierres automáticos.');
        }
        $evidenceValid = $evidenceRef !== null
            && preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $evidenceRef) === 1;
        if (($evidenceRef !== null && !$evidenceValid)
            || ($nextState === self::CLOSURE_REVIEW && !$evidenceValid)) {
            throw new DomainException('La solicitud de cierre requiere evidencia ficticia.');
        }

        $this->state = $nextState;
        $this->evidenceRef = $evidenceRef;
        $this->actorRef = $actorRef;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function tenantRef(): string
    {
        return $this->tenantRef;
    }

    public function recordRef(): string
    {
        return $this->recordRef;
    }

    public function actorRef(): string
    {
        return $this->actorRef;
    }

    public function state(): string
    {
        return $this->state;
    }

    /** @return array<string,string|null> */
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

    private static function validDate(string $date): bool
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $part) !== 1) {
            return false;
        }

        return checkdate((int) $part[2], (int) $part[3], (int) $part[1]);
    }
}

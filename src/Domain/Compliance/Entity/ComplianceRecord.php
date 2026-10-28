<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/**
 * M06: immutable, append-only synthetic compliance evidence event.
 * No legal finding, real company information, or automatic closure is stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'condor_compliance_record')]
#[ORM\UniqueConstraint(name: 'uniq_compliance_case_version', columns: ['tenant_ref', 'company_ref', 'obligation_ref', 'version'])]
#[ORM\Index(name: 'idx_compliance_tenant_company', columns: ['tenant_ref', 'company_ref'])]
#[ORM\Index(name: 'idx_compliance_previous', columns: ['tenant_ref', 'company_ref', 'previous_record_id'])]
final class ComplianceRecord
{
    public const STATUS_PENDIENTE = 'PENDIENTE';
    public const STATUS_EN_REVISION = 'EN_REVISION';
    public const STATUS_OBSERVADA = 'OBSERVADA';
    public const STATUS_CERRADA = 'CERRADA';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 80)]
    private string $tenantRef;

    #[ORM\Column(name: 'company_ref', type: 'string', length: 80)]
    private string $companyRef;

    #[ORM\Column(name: 'obligation_ref', type: 'string', length: 80)]
    private string $obligationRef;

    #[ORM\Column(name: 'evidence_ref', type: 'string', length: 80)]
    private string $evidenceRef;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(name: 'previous_record_id', type: 'string', length: 26, nullable: true)]
    private ?string $previousRecordId;

    #[ORM\Column(name: 'previous_status', type: 'string', length: 20, nullable: true)]
    private ?string $previousStatus;

    #[ORM\Column(name: 'audit_action', type: 'string', length: 20)]
    private string $auditAction;

    #[ORM\Column(name: 'actor_ref', type: 'string', length: 80)]
    private string $actorRef;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'fingerprint', type: 'string', length: 64, options: ['fixed' => true])]
    private string $fingerprint;

    private function __construct(
        string $tenantRef,
        string $companyRef,
        string $obligationRef,
        string $evidenceRef,
        string $actorRef,
        string $status,
        int $version,
        ?string $previousRecordId,
        ?string $previousStatus,
        string $auditAction,
    ) {
        self::assertFixtureScope($tenantRef, $companyRef, $actorRef);
        self::assertFixture($obligationRef, 'obligation');
        self::assertFixture($evidenceRef, 'evidence');
        if ($version < 1 || $version > 2147483647) {
            throw new DomainException('Versión de auditoría inválida.');
        }
        $this->id = UlidFactory::new();
        $this->tenantRef = $tenantRef;
        $this->companyRef = $companyRef;
        $this->obligationRef = $obligationRef;
        $this->evidenceRef = $evidenceRef;
        $this->actorRef = $actorRef;
        $this->status = $status;
        $this->version = $version;
        $this->previousRecordId = $previousRecordId;
        $this->previousStatus = $previousStatus;
        $this->auditAction = $auditAction;
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = hash('sha256', json_encode($this->values(), JSON_THROW_ON_ERROR));
    }

    public static function open(
        string $tenantRef,
        string $companyRef,
        string $obligationRef,
        string $evidenceRef,
        string $actorRef,
    ): self {
        return new self(
            $tenantRef, $companyRef, $obligationRef, $evidenceRef, $actorRef,
            self::STATUS_PENDIENTE, 1, null, null, 'CREADA',
        );
    }

    /**
     * An explicitly authorized human review is required for every transition.
     * A closed case is terminal, and no deadline or inferred evaluation closes it.
     */
    public function transition(string $nextStatus, string $actorRef, bool $humanApproved): self
    {
        if (!$humanApproved || $this->status === self::STATUS_CERRADA) {
            throw new DomainException('Transición requiere decisión humana explícita.');
        }
        $allowed = match ($this->status) {
            self::STATUS_PENDIENTE => [self::STATUS_EN_REVISION],
            self::STATUS_EN_REVISION => [self::STATUS_OBSERVADA, self::STATUS_CERRADA],
            self::STATUS_OBSERVADA => [self::STATUS_EN_REVISION],
            default => [],
        };
        if (!in_array($nextStatus, $allowed, true)) {
            throw new DomainException('Transición de estado no permitida.');
        }

        return new self(
            $this->tenantRef, $this->companyRef, $this->obligationRef,
            $this->evidenceRef, $actorRef, $nextStatus, $this->version + 1,
            $this->id, $this->status, 'CAMBIO_EXPLICITO',
        );
    }

    public static function assertFixtureScope(string $tenantRef, string $companyRef, string $actorRef): void
    {
        self::assertFixture($tenantRef, 'tenant');
        self::assertFixture($companyRef, 'company');
        self::assertFixture($actorRef, 'actor');
    }

    public static function assertFixture(string $value, string $kind): void
    {
        if (!in_array($kind, ['tenant', 'company', 'actor', 'obligation', 'evidence'], true)
            || preg_match('/^fixture-'.preg_quote($kind, '/').'-[a-z0-9][a-z0-9-]{0,51}$/D', $value) !== 1
        ) {
            throw new DomainException('Solo referencias sintéticas tipadas sin datos reales.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantRef(): string { return $this->tenantRef; }
    public function companyRef(): string { return $this->companyRef; }
    public function obligationRef(): string { return $this->obligationRef; }
    public function status(): string { return $this->status; }
    public function version(): int { return $this->version; }
    public function previousRecordId(): ?string { return $this->previousRecordId; }
    public function previousStatus(): ?string { return $this->previousStatus; }
    public function actorRef(): string { return $this->actorRef; }

    /**
     * @return array{id:string,tenant_ref:string,company_ref:string,obligation_ref:string,
     *     evidence_ref:string,status:string,version:int,previous_record_id:?string,
     *     previous_status:?string,audit_action:string,actor_ref:string,occurred_at:string,
     *     fingerprint:string}
     */
    public function snapshot(): array
    {
        return [...$this->values(), 'fingerprint' => $this->fingerprint];
    }

    /**
     * @return array{id:string,tenant_ref:string,company_ref:string,obligation_ref:string,
     *     evidence_ref:string,status:string,version:int,previous_record_id:?string,
     *     previous_status:?string,audit_action:string,actor_ref:string,occurred_at:string}
     */
    private function values(): array
    {
        return [
            'id' => $this->id,
            'tenant_ref' => $this->tenantRef,
            'company_ref' => $this->companyRef,
            'obligation_ref' => $this->obligationRef,
            'evidence_ref' => $this->evidenceRef,
            'status' => $this->status,
            'version' => $this->version,
            'previous_record_id' => $this->previousRecordId,
            'previous_status' => $this->previousStatus,
            'audit_action' => $this->auditAction,
            'actor_ref' => $this->actorRef,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}

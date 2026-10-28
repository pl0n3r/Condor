<?php

declare(strict_types=1);

namespace App\Domain\Risk\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Append-only M12 record. Synthetic fixtures only; never a legal risk decision. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_risk_record')]
#[ORM\UniqueConstraint(name: 'uniq_risk_scope_version', columns: ['tenant_ref', 'company_ref', 'risk_ref', 'version'])]
#[ORM\Index(name: 'idx_risk_company_state', columns: ['tenant_ref', 'company_ref', 'state'])]
final class RiskRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'REQUIERE_REVISION';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 80)]
    private string $tenantRef;

    #[ORM\Column(name: 'company_ref', type: 'string', length: 80)]
    private string $companyRef;

    #[ORM\Column(name: 'risk_ref', type: 'string', length: 80)]
    private string $riskRef;

    #[ORM\Column(type: 'string', length: 20)]
    private string $category;

    #[ORM\Column(type: 'string', length: 20)]
    private string $state;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(name: 'previous_id', type: 'string', length: 26, nullable: true)]
    private ?string $previousId;

    #[ORM\Column(name: 'previous_state', type: 'string', length: 20, nullable: true)]
    private ?string $previousState;

    #[ORM\Column(name: 'audit_action', type: 'string', length: 20)]
    private string $auditAction;

    #[ORM\Column(name: 'actor_ref', type: 'string', length: 80)]
    private string $actorRef;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 64)]
    private string $fingerprint;

    private function __construct(
        string $tenantRef, string $companyRef, string $riskRef, string $category,
        string $actorRef, string $state, int $version, ?self $previous,
    ) {
        self::assertScope($tenantRef, $companyRef, $actorRef);
        self::assertFixture($riskRef, 'risk');
        if (!in_array($category, ['operativo', 'financiero', 'legal', 'tecnologico'], true)
            || !in_array($state, [self::PENDING, self::REVIEW], true)
            || $version < 1 || $version > 2147483647
            || ($previous === null) !== ($version === 1)
        ) {
            throw new DomainException('Categoría, estado o versión de riesgo inválidos.');
        }
        $this->id = UlidFactory::new();
        $this->tenantRef = $tenantRef;
        $this->companyRef = $companyRef;
        $this->riskRef = $riskRef;
        $this->category = $category;
        $this->actorRef = $actorRef;
        $this->state = $state;
        $this->version = $version;
        $this->previousId = $previous?->id();
        $this->previousState = $previous?->state();
        $this->auditAction = $previous === null ? 'CREADA' : 'CAMBIO_EXPLICITO';
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = hash('sha256', json_encode($this->values(), JSON_THROW_ON_ERROR));
    }

    public static function open(
        string $tenantRef, string $companyRef, string $riskRef, string $category, string $actorRef,
    ): self {
        return new self($tenantRef, $companyRef, $riskRef, $category, $actorRef, self::PENDING, 1, null);
    }

    /** Only an explicitly authorized human may change the review state. No closed state exists. */
    public function transition(string $nextState, string $actorRef, bool $humanApproved): self
    {
        if (!$humanApproved
            || !in_array($nextState, [self::PENDING, self::REVIEW], true)
            || $nextState === $this->state
        ) {
            throw new DomainException('La transición requiere revisión humana explícita.');
        }
        return new self(
            $this->tenantRef, $this->companyRef, $this->riskRef, $this->category,
            $actorRef, $nextState, $this->version + 1, $this,
        );
    }

    public static function assertScope(string $tenant, string $company, string $actor): void
    {
        self::assertFixture($tenant, 'tenant');
        self::assertFixture($company, 'company');
        self::assertFixture($actor, 'actor');
    }

    public static function assertFixture(string $reference, string $kind): void
    {
        if (!in_array($kind, ['tenant', 'company', 'actor', 'risk'], true)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $reference) !== 1
            || preg_match('/[0-9]{7,}/', $reference) === 1
        ) {
            throw new DomainException('Solo referencias tipadas y ficticias, sin datos reales.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantRef(): string { return $this->tenantRef; }
    public function companyRef(): string { return $this->companyRef; }
    public function riskRef(): string { return $this->riskRef; }
    public function actorRef(): string { return $this->actorRef; }
    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
    public function previousId(): ?string { return $this->previousId; }
    public function previousState(): ?string { return $this->previousState; }

    /** @return array<string, string|int|null> */
    public function snapshot(): array
    {
        return [...$this->values(), 'fingerprint' => $this->fingerprint];
    }

    /** @return array<string, string|int|null> */
    private function values(): array
    {
        return [
            'id' => $this->id,
            'tenant_ref' => $this->tenantRef,
            'company_ref' => $this->companyRef,
            'risk_ref' => $this->riskRef,
            'category' => $this->category,
            'state' => $this->state,
            'version' => $this->version,
            'previous_id' => $this->previousId,
            'previous_state' => $this->previousState,
            'audit_action' => $this->auditAction,
            'actor_ref' => $this->actorRef,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}

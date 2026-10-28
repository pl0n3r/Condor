<?php

declare(strict_types=1);

namespace App\Domain\SocialSecurity\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** M05 immutable synthetic social-security case history; no automated findings or closure. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_social_security_record')]
#[ORM\UniqueConstraint(name: 'uniq_social_security_scope_version', columns: ['tenant_ref', 'company_ref', 'social_security_ref', 'version'])]
#[ORM\Index(name: 'idx_social_security_company_state', columns: ['tenant_ref', 'company_ref', 'state'])]
final class SocialSecurityRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'REQUIERE_REVISION';

    #[ORM\Id, ORM\Column(type: 'string', length: 26)]
    private string $id;
    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 80)]
    private string $tenantKey;
    #[ORM\Column(name: 'company_ref', type: 'string', length: 80)]
    private string $companyKey;
    #[ORM\Column(name: 'social_security_ref', type: 'string', length: 80)]
    private string $caseKey;
    #[ORM\Column(type: 'string', length: 20)]
    private string $category;
    #[ORM\Column(type: 'string', length: 20)]
    private string $state;
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;
    #[ORM\Column(name: 'previous_id', type: 'string', length: 26, nullable: true)]
    private ?string $priorId;
    #[ORM\Column(name: 'previous_state', type: 'string', length: 20, nullable: true)]
    private ?string $priorState;
    #[ORM\Column(name: 'audit_action', type: 'string', length: 20)]
    private string $action;
    #[ORM\Column(name: 'actor_ref', type: 'string', length: 80)]
    private string $actorKey;
    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;
    #[ORM\Column(type: 'string', length: 64)]
    private string $fingerprint;

    private function __construct(
        string $tenant, string $company, string $case, string $kind,
        string $actor, string $status, ?self $predecessor,
    ) {
        self::assertScope($tenant, $company, $actor);
        self::assertFixture($case, 'social_security');
        if (!in_array($kind, ['afiliacion', 'aporte', 'novedad', 'soporte'], true)
            || !in_array($status, [self::PENDING, self::REVIEW], true)
            || ($predecessor !== null && $predecessor->version >= 2147483647)
        ) {
            throw new DomainException('Categoría o estado sintético no permitido.');
        }
        $this->id = UlidFactory::new();
        $this->tenantKey = $tenant;
        $this->companyKey = $company;
        $this->caseKey = $case;
        $this->category = $kind;
        $this->state = $status;
        $this->actorKey = $actor;
        $this->version = ($predecessor->version ?? 0) + 1;
        $this->priorId = $predecessor?->id;
        $this->priorState = $predecessor?->state;
        $this->action = $predecessor === null ? 'CREADA' : 'CAMBIO_EXPLICITO';
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = hash('sha256', json_encode($this->attributes(), JSON_THROW_ON_ERROR));
    }

    public static function open(
        string $tenantRef, string $companyRef, string $social_securityRef,
        string $category, string $actorRef,
    ): self {
        return new self($tenantRef, $companyRef, $social_securityRef, $category, $actorRef, self::PENDING, null);
    }

    /** A human authorizes each change; neither state is considered complete. */
    public function transition(string $nextState, string $actorRef, bool $humanApproved): self
    {
        if (!$humanApproved || $nextState === $this->state
            || !in_array($nextState, [self::PENDING, self::REVIEW], true)
        ) {
            throw new DomainException('Los cambios de Seguridad social requieren autorización explícita.');
        }
        return new self(
            $this->tenantKey, $this->companyKey, $this->caseKey,
            $this->category, $actorRef, $nextState, $this,
        );
    }

    public static function assertScope(string $tenant, string $company, string $actor): void
    {
        foreach (['tenant' => $tenant, 'company' => $company, 'actor' => $actor] as $kind => $value) {
            self::assertFixture($value, $kind);
        }
    }

    public static function assertFixture(string $reference, string $kind): void
    {
        $allowed = ['tenant', 'company', 'actor', 'social_security'];
        if (!in_array($kind, $allowed, true)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $reference) !== 1
            || preg_match('/[0-9]{7,}/', $reference) === 1
        ) {
            throw new DomainException('Seguridad social permite solamente identificadores ficticios tipados.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantRef(): string { return $this->tenantKey; }
    public function companyRef(): string { return $this->companyKey; }
    public function social_securityRef(): string { return $this->caseKey; }
    public function actorRef(): string { return $this->actorKey; }
    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
    public function previousId(): ?string { return $this->priorId; }
    public function previousState(): ?string { return $this->priorState; }

    /** @return array<string, string|int|null> */
    public function snapshot(): array
    {
        return $this->attributes() + ['fingerprint' => $this->fingerprint];
    }

    /** @return array<string, string|int|null> */
    private function attributes(): array
    {
        return [
            'id' => $this->id,
            'tenant_ref' => $this->tenantKey,
            'company_ref' => $this->companyKey,
            'social_security_ref' => $this->caseKey,
            'category' => $this->category,
            'state' => $this->state,
            'version' => $this->version,
            'previous_id' => $this->priorId,
            'previous_state' => $this->priorState,
            'audit_action' => $this->action,
            'actor_ref' => $this->actorKey,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}

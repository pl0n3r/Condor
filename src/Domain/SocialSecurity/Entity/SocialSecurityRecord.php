<?php

declare(strict_types=1);

namespace App\Domain\SocialSecurity\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Pre-live, fixture-only versioned social-security record. Each change is human-authorized. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_social_security_record')]
#[ORM\UniqueConstraint(name: 'uniq_social_security_scope_version', columns: ['tenant_ref', 'company_ref', 'social_security_ref', 'version'])]
#[ORM\Index(name: 'idx_social_security_company_state', columns: ['tenant_ref', 'company_ref', 'state'])]
final class SocialSecurityRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'REQUIERE_REVISION';

    private const CATEGORIES = [
        'afiliacion' => true,
        'aporte' => true,
        'novedad' => true,
        'soporte' => true,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'social_security_ref', length: 80)]
    private string $caseKey;

    #[ORM\Column(name: 'tenant_ref', length: 80)]
    private string $tenantKey;

    #[ORM\Column(name: 'company_ref', length: 80)]
    private string $companyKey;

    #[ORM\Column(name: 'actor_ref', length: 80)]
    private string $actorKey;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 20)]
    private string $state;

    #[ORM\Column(name: 'previous_id', length: 26, nullable: true)]
    private ?string $priorId;

    #[ORM\Column(name: 'previous_state', length: 20, nullable: true)]
    private ?string $priorState;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(name: 'audit_action', length: 20)]
    private string $action;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    private function __construct(
        string $tenant, string $company, string $case,
        string $kind, string $actor, string $status,
        ?self $previous,
    ) {
        self::assertScope($tenant, $company, $actor);
        self::assertFixture($case, 'social_security');
        if (!isset(self::CATEGORIES[$kind])) {
            throw new DomainException('Categoría de seguridad social desconocida.');
        }
        if ($status !== self::PENDING && $status !== self::REVIEW) {
            throw new DomainException('Solo se permiten estados pendientes de actuación humana.');
        }
        if ($previous !== null && $previous->version >= 2147483647) {
            throw new DomainException('Se agotó el historial versionado.');
        }

        $this->id = UlidFactory::new();
        $this->caseKey = $case;
        $this->tenantKey = $tenant;
        $this->companyKey = $company;
        $this->actorKey = $actor;
        $this->category = $kind;
        $this->state = $status;
        $this->priorId = $previous?->id;
        $this->priorState = $previous?->state;
        $this->version = 1 + ($previous === null ? 0 : $previous->version);
        $this->action = $previous === null ? 'CREADA' : 'CAMBIO_EXPLICITO';
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = hash('sha256', json_encode($this->attributes(), JSON_THROW_ON_ERROR));
    }

    /** Open a synthetic case; no side effects or live workflow. */
    public static function open(
        string $tenantRef, string $companyRef, string $social_securityRef,
        string $category, string $actorRef,
    ): self {
        return new self(
            $tenantRef, $companyRef, $social_securityRef,
            $category, $actorRef, self::PENDING, null,
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
        if (!in_array($kind, ['tenant', 'company', 'social_security', 'actor'], true)) {
            throw new DomainException('Tipo de referencia desconocido.');
        }
        $prefix = 'fixture:'.$kind.'/';
        if (!str_starts_with($reference, $prefix)) {
            throw new DomainException('Referencia externa no admitida en seguridad social.');
        }
        $identifier = substr($reference, strlen($prefix));
        if (preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/D', $identifier) !== 1
            || preg_match('/[0-9]{7,}/', $identifier) === 1
        ) {
            throw new DomainException('El identificador debe ser ficticio, breve y sin PII.');
        }
    }

    public function transition(string $nextState, string $actorRef, bool $humanApproved): self
    {
        if ($humanApproved !== true) {
            throw new DomainException('No se ejecutan cambios sin decisión humana.');
        }
        if ($nextState === $this->state
            || ($nextState !== self::PENDING && $nextState !== self::REVIEW)
        ) {
            throw new DomainException('Transición inválida: el cierre automático no existe.');
        }
        return new self(
            $this->tenantKey, $this->companyKey, $this->caseKey,
            $this->category, $actorRef, $nextState, $this,
        );
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
    private function attributes(): array
    {
        $identity = [
            'id' => $this->id,
            'tenant_ref' => $this->tenantKey,
            'company_ref' => $this->companyKey,
            'social_security_ref' => $this->caseKey,
            'category' => $this->category,
            'state' => $this->state,
        ];
        $history = [
            'version' => $this->version,
            'previous_id' => $this->priorId,
            'previous_state' => $this->priorState,
            'audit_action' => $this->action,
            'actor_ref' => $this->actorKey,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
        return $identity + $history;
    }

    /** @return array<string, string|int|null> */
    public function snapshot(): array
    {
        $fields = $this->attributes();
        $fields['fingerprint'] = $this->fingerprint;
        return $fields;
    }
}

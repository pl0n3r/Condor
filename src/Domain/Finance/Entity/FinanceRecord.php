<?php

declare(strict_types=1);

namespace App\Domain\Finance\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Append-only M07 synthetic ledger event. No customer or tax identity. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_finance_record')]
#[ORM\UniqueConstraint(name: 'uniq_finance_scope_version', columns: ['tenant_ref', 'legal_entity_ref', 'record_ref', 'version'])]
#[ORM\Index(name: 'idx_finance_scope_kind', columns: ['tenant_ref', 'legal_entity_ref', 'kind'])]
final class FinanceRecord
{
    public const DRAFT = 'BORRADOR';
    public const REVIEW = 'REQUIERE_REVISION';

    /** @var array<string,string> */
    private const KINDS = [
        'ENTRY' => 'finance-entry',
        'ACCOUNT' => 'finance-account',
        'BANK' => 'finance-movement',
        'BUDGET' => 'finance-budget',
        'PERIOD' => 'finance-period',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 80)]
    private string $tenantRef;

    #[ORM\Column(name: 'legal_entity_ref', type: 'string', length: 80)]
    private string $legalEntityRef;

    #[ORM\Column(name: 'record_ref', type: 'string', length: 80)]
    private string $recordRef;

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind;

    #[ORM\Column(name: 'amount_decimal', type: 'string', length: 16)]
    private string $amountDecimal;

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
        string $tenantRef, string $legalEntityRef, string $recordRef,
        string $kind, string $amountDecimal, string $actorRef,
        string $state, int $version, ?self $previous,
    ) {
        self::assertScope($tenantRef, $legalEntityRef, $actorRef);
        if (!isset(self::KINDS[$kind])) {
            throw new DomainException('Tipo financiero de prueba desconocido.');
        }
        self::assertFixture($recordRef, self::KINDS[$kind]);
        if (preg_match('/\A(?:0|[1-9][0-9]{0,11})\.[0-9]{2}\z/D', $amountDecimal) !== 1) {
            throw new DomainException('Monto COP sintético inválido.');
        }
        if (!in_array($state, [self::DRAFT, self::REVIEW], true)
            || $version < 1 || $version > 2147483647
            || ($previous === null) !== ($version === 1)
        ) {
            throw new DomainException('Estado o historial inválido.');
        }
        $this->id = UlidFactory::new();
        $this->tenantRef = $tenantRef;
        $this->legalEntityRef = $legalEntityRef;
        $this->recordRef = $recordRef;
        $this->kind = $kind;
        $this->amountDecimal = $amountDecimal;
        $this->state = $state;
        $this->version = $version;
        $this->previousId = $previous?->id();
        $this->previousState = $previous?->state();
        $this->actorRef = $actorRef;
        $this->auditAction = $previous === null ? 'CREADA' : 'CAMBIO_EXPLICITO';
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = hash('sha256', json_encode($this->values(), JSON_THROW_ON_ERROR));
    }

    public static function open(
        string $tenantRef, string $legalEntityRef, string $recordRef,
        string $kind, string $amountDecimal, string $actorRef,
    ): self {
        return new self(
            $tenantRef, $legalEntityRef, $recordRef, $kind, $amountDecimal,
            $actorRef, self::DRAFT, 1, null,
        );
    }

    /** Only a positive explicit human decision can move draft to review. No closure. */
    public function sendToReview(string $actorRef, bool $humanApproved): self
    {
        if (!$humanApproved || $this->state !== self::DRAFT) {
            throw new DomainException('Revisión financiera requiere autorización explícita.');
        }

        return new self(
            $this->tenantRef, $this->legalEntityRef, $this->recordRef,
            $this->kind, $this->amountDecimal, $actorRef,
            self::REVIEW, $this->version + 1, $this,
        );
    }

    public static function assertScope(string $tenant, string $entity, string $actor): void
    {
        self::assertFixture($tenant, 'tenant');
        self::assertFixture($entity, 'legal-entity');
        self::assertFixture($actor, 'actor');
    }

    public static function assertFixture(string $value, string $kind): void
    {
        if (!in_array($kind, ['tenant', 'legal-entity', 'actor', ...array_values(self::KINDS)], true)
            || preg_match('~\Afixture:'.preg_quote($kind, '~').'/[a-z][a-z0-9_-]{0,47}\z~D', $value) !== 1
            || preg_match('/[0-9]{7,}/', $value) === 1
        ) {
            throw new DomainException('Referencia estrictamente ficticia requerida.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantRef(): string { return $this->tenantRef; }
    public function legalEntityRef(): string { return $this->legalEntityRef; }
    public function recordRef(): string { return $this->recordRef; }
    public function actorRef(): string { return $this->actorRef; }
    public function version(): int { return $this->version; }
    public function state(): string { return $this->state; }
    public function previousId(): ?string { return $this->previousId; }
    public function previousState(): ?string { return $this->previousState; }

    /**
     * @return array{id:string,tenant_ref:string,legal_entity_ref:string,record_ref:string,
     * kind:string,amount_decimal:string,state:string,version:int,previous_id:?string,
     * previous_state:?string,audit_action:string,actor_ref:string,occurred_at:string,fingerprint:string}
     */
    public function snapshot(): array
    {
        return [...$this->values(), 'fingerprint' => $this->fingerprint];
    }

    /**
     * @return array{id:string,tenant_ref:string,legal_entity_ref:string,record_ref:string,
     * kind:string,amount_decimal:string,state:string,version:int,previous_id:?string,
     * previous_state:?string,audit_action:string,actor_ref:string,occurred_at:string}
     */
    private function values(): array
    {
        return [
            'id' => $this->id,
            'tenant_ref' => $this->tenantRef,
            'legal_entity_ref' => $this->legalEntityRef,
            'record_ref' => $this->recordRef,
            'kind' => $this->kind,
            'amount_decimal' => $this->amountDecimal,
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

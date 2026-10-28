<?php

declare(strict_types=1);

namespace App\Domain\Documents\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** M08: immutable, synthetic-only document event. Never stores document content. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_document_record')]
#[ORM\UniqueConstraint(name: 'uniq_document_company_version', columns: ['tenant_ref', 'legal_entity_ref', 'record_ref', 'version'])]
final class DocumentRecord
{
    public const DRAFT = 'BORRADOR';
    public const REVIEW = 'REQUIERE_REVISION';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 96)]
    private string $tenantRef;

    #[ORM\Column(name: 'legal_entity_ref', type: 'string', length: 96)]
    private string $legalEntityRef;

    #[ORM\Column(name: 'record_ref', type: 'string', length: 96)]
    private string $recordRef;

    #[ORM\Column(name: 'version_ref', type: 'string', length: 96)]
    private string $versionRef;

    #[ORM\Column(type: 'string', length: 16)]
    private string $classification;

    #[ORM\Column(type: 'string', length: 24)]
    private string $state;

    #[ORM\Column(type: 'integer')]
    private int $version;

    #[ORM\Column(name: 'previous_id', type: 'string', length: 26, nullable: true)]
    private ?string $previousId;

    #[ORM\Column(name: 'actor_ref', type: 'string', length: 96)]
    private string $actorRef;

    #[ORM\Column(name: 'audit_action', type: 'string', length: 24)]
    private string $auditAction;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 64)]
    private string $fingerprint;

    private function __construct(
        string $tenantRef, string $legalEntityRef, string $recordRef,
        string $versionRef, string $classification, string $actorRef,
        string $state, int $version, ?self $previous,
    ) {
        self::assertScope($tenantRef, $legalEntityRef, $actorRef);
        self::assertFixture($recordRef, 'dossier');
        self::assertFixture($versionRef, 'version');
        if (!in_array($classification, ['PUBLICA', 'INTERNA', 'RESERVADA'], true)
            || !in_array($state, [self::DRAFT, self::REVIEW], true)
            || $version < 1 || $version > 2147483647
            || (($previous === null) !== ($version === 1))) {
            throw new DomainException('Document fixture, version or state is invalid.');
        }
        $this->id = UlidFactory::new();
        $this->tenantRef = $tenantRef;
        $this->legalEntityRef = $legalEntityRef;
        $this->recordRef = $recordRef;
        $this->versionRef = $versionRef;
        $this->classification = $classification;
        $this->actorRef = $actorRef;
        $this->state = $state;
        $this->version = $version;
        $this->previousId = $previous?->id();
        $this->auditAction = $previous === null ? 'CREADA' : 'REVISION_EXPLICITA';
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = hash('sha256', json_encode($this->values(), JSON_THROW_ON_ERROR));
    }

    public static function open(
        string $tenantRef, string $legalEntityRef, string $recordRef,
        string $versionRef, string $classification, string $actorRef,
    ): self {
        return new self($tenantRef, $legalEntityRef, $recordRef,
            $versionRef, $classification, $actorRef, self::DRAFT, 1, null);
    }

    /** Explicit reviewer action only; neither approval nor closure is available. */
    public function sendToReview(string $actorRef, bool $humanApproved): self
    {
        self::assertFixture($actorRef, 'actor');
        if (!$humanApproved || $this->state !== self::DRAFT || $actorRef === $this->actorRef) {
            throw new DomainException('Document review requires a different authorized actor.');
        }
        return new self($this->tenantRef, $this->legalEntityRef, $this->recordRef,
            $this->versionRef, $this->classification, $actorRef,
            self::REVIEW, $this->version + 1, $this);
    }

    public static function assertFixture(mixed $value, string $kind): void
    {
        if (!in_array($kind, ['tenant', 'legal-entity', 'actor', 'dossier', 'version'], true)
            || !is_string($value)
            || strlen($value) > 96
            || preg_match('~\Afixture:' . preg_quote($kind, '~') . '/[a-z][a-z0-9_-]{0,47}\z~D', $value) !== 1
            || preg_match('/[0-9]{7,}/', $value) === 1) {
            throw new DomainException('Only synthetic references are permitted.');
        }
    }

    public static function assertScope(string $tenant, string $legalEntity, string $actor): void
    {
        self::assertFixture($tenant, 'tenant');
        self::assertFixture($legalEntity, 'legal-entity');
        self::assertFixture($actor, 'actor');
    }

    public function id(): string { return $this->id; }
    public function tenantRef(): string { return $this->tenantRef; }
    public function legalEntityRef(): string { return $this->legalEntityRef; }
    public function recordRef(): string { return $this->recordRef; }
    public function actorRef(): string { return $this->actorRef; }
    public function version(): int { return $this->version; }
    public function previousId(): ?string { return $this->previousId; }
    public function state(): string { return $this->state; }
    public function auditAction(): string { return $this->auditAction; }

    /**
     * @return array{id:string,tenant_ref:string,legal_entity_ref:string,record_ref:string,
     * version_ref:string,classification:string,state:string,version:int,previous_id:?string,
     * actor_ref:string,audit_action:string,occurred_at:string,fingerprint:string}
     */
    public function snapshot(): array
    {
        return [...$this->values(), 'fingerprint' => $this->fingerprint];
    }

    /**
     * @return array{id:string,tenant_ref:string,legal_entity_ref:string,record_ref:string,
     * version_ref:string,classification:string,state:string,version:int,previous_id:?string,
     * actor_ref:string,audit_action:string,occurred_at:string}
     */
    private function values(): array
    {
        return [
            'id' => $this->id, 'tenant_ref' => $this->tenantRef,
            'legal_entity_ref' => $this->legalEntityRef, 'record_ref' => $this->recordRef,
            'version_ref' => $this->versionRef, 'classification' => $this->classification,
            'state' => $this->state, 'version' => $this->version,
            'previous_id' => $this->previousId, 'actor_ref' => $this->actorRef,
            'audit_action' => $this->auditAction,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}

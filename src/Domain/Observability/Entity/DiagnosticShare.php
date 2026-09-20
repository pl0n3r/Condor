<?php

declare(strict_types=1);

namespace App\Domain\Observability\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'condor_diagnostic_share')]
#[ORM\Index(name: 'idx_diagnostic_share_expires', columns: ['expires_at'])]
class DiagnosticShare
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ErrorIncident::class)]
    #[ORM\JoinColumn(name: 'incident_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ErrorIncident $incident;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        ErrorIncident $incident,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ) {
        $this->id = UlidFactory::new();
        $this->incident = $incident;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function incident(): ErrorIncident
    {
        return $this->incident;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revoke(): void
    {
        if ($this->revokedAt === null) {
            $this->revokedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now;
    }
}

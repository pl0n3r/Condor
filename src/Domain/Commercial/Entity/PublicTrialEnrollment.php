<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** A single trial entitlement per normalized email, even after account deletion. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_public_trial_enrollment')]
#[ORM\UniqueConstraint(name: 'uniq_public_trial_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_public_trial_token', columns: ['token_hash'])]
final class PublicTrialEnrollment
{
    #[ORM\Id, ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable')]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'consumed_tenant_id', type: 'string', length: 26, nullable: true)]
    private ?string $consumedTenantId = null;

    public function __construct(string $email, string $tokenHash, DateTimeImmutable $now)
    {
        $this->id = UlidFactory::new();
        $this->email = strtolower(trim($email));
        $this->rotate($tokenHash, $now);
    }

    public function canReissue(DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $now >= $this->issuedAt->modify('+5 minutes');
    }

    public function reissue(string $tokenHash, DateTimeImmutable $now): void
    {
        if (!$this->canReissue($now)) {
            throw new DomainException('La solicitud no está disponible para reenvío.');
        }
        $this->rotate($tokenHash, $now);
    }

    private function rotate(string $tokenHash, DateTimeImmutable $now): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            throw new DomainException('Huella de verificación inválida.');
        }
        $this->tokenHash = $tokenHash;
        $this->issuedAt = $now;
        $this->expiresAt = $now->modify('+30 minutes');
    }

    public function email(): string { return $this->email; }

    public function canConsume(DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $now <= $this->expiresAt;
    }

    public function markConsumed(string $tenantId, DateTimeImmutable $now): void
    {
        if (!$this->canConsume($now) || trim($tenantId) === '') {
            throw new DomainException('Verificación no disponible.');
        }
        $this->consumedAt = $now;
        $this->consumedTenantId = $tenantId;
        // Permanently invalidate the bearer token without deleting trial history.
        $this->tokenHash = hash('sha256', random_bytes(32));
    }
}

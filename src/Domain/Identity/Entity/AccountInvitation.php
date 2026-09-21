<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_account_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_account_invitation_user', columns: ['user_id'])]
class AccountInvitation
{
    public const KIND_PLATFORM_STAFF = 'platform_staff';
    public const KIND_TENANT_MEMBER = 'tenant_member';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant;

    #[ORM\Column(type: 'string', length: 32)]
    private string $kind;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'created_by_user_id', type: 'string', length: 26)]
    private string $createdByUserId;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        ?Tenant $tenant,
        string $kind,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        string $createdByUserId,
    ) {
        if ($user->isActive()) {
            throw new DomainException('La invitación requiere una cuenta pendiente de activación.');
        }
        if (!in_array($kind, [self::KIND_PLATFORM_STAFF, self::KIND_TENANT_MEMBER], true)) {
            throw new DomainException('El tipo de invitación no es válido.');
        }
        if ($kind === self::KIND_TENANT_MEMBER && $tenant === null) {
            throw new DomainException('La invitación de empresa requiere un tenant.');
        }
        if ($kind === self::KIND_PLATFORM_STAFF && $tenant !== null) {
            throw new DomainException('El staff de plataforma no usa membresía de tenant.');
        }

        $this->id = UlidFactory::new();
        $this->user = $user;
        $this->tenant = $tenant;
        $this->kind = $kind;
        $this->tokenHash = self::normalizeHash($tokenHash);
        $this->createdByUserId = $createdByUserId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function user(): User { return $this->user; }
    public function tenant(): ?Tenant { return $this->tenant; }
    public function kind(): string { return $this->kind; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function consumedAt(): ?DateTimeImmutable { return $this->consumedAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }

    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null
            && $this->revokedAt === null
            && $this->expiresAt > $now;
    }

    public function reissue(string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        if ($this->consumedAt !== null) {
            throw new DomainException('Una invitación ya consumida no puede reenviarse.');
        }

        $this->tokenHash = self::normalizeHash($tokenHash);
        $this->expiresAt = $expiresAt;
        $this->revokedAt = null;
        $this->touch();
    }

    public function revoke(DateTimeImmutable $now): void
    {
        if ($this->consumedAt !== null) {
            throw new DomainException('Una invitación ya consumida no puede revocarse.');
        }

        $this->revokedAt = $now;
        $this->touch($now);
    }

    public function consume(DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new DomainException('La invitación ya no está disponible.');
        }

        $this->consumedAt = $now;
        $this->touch($now);
    }

    private function touch(?DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function normalizeHash(string $tokenHash): string
    {
        $tokenHash = strtolower(trim($tokenHash));
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            throw new DomainException('El hash de invitación no es válido.');
        }

        return $tokenHash;
    }
}

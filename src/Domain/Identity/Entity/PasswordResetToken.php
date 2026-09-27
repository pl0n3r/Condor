<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_password_reset')]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_user', columns: ['user_id'])]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
    private string $tokenHash;

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

    public function __construct(User $user, string $tokenHash, DateTimeImmutable $expiresAt)
    {
        $this->id = UlidFactory::new();
        $this->user = $user;
        $this->tokenHash = self::normalizeHash($tokenHash);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function consumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null
            && $this->revokedAt === null
            && $this->expiresAt > $now;
    }

    public function reissue(string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->tokenHash = self::normalizeHash($tokenHash);
        $this->expiresAt = $expiresAt;
        $this->consumedAt = null;
        $this->revokedAt = null;
        $this->updatedAt = $now;
    }

    public function consume(DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new DomainException('El enlace de recuperación ya no está disponible.');
        }

        $this->consumedAt = $now;
        $this->updatedAt = $now;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        if ($this->consumedAt !== null) {
            throw new DomainException('Un enlace consumido no puede revocarse.');
        }

        $this->revokedAt = $now;
        $this->updatedAt = $now;
    }

    private static function normalizeHash(string $tokenHash): string
    {
        $tokenHash = strtolower(trim($tokenHash));
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            throw new DomainException('El hash de recuperación no es válido.');
        }

        return $tokenHash;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'condor_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ROLE_PLATFORM_OWNER = 'ROLE_PLATFORM_OWNER';
    public const ROLE_PLATFORM_STAFF = 'ROLE_PLATFORM_STAFF';
    public const ROLE_LEGACY_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'display_name', type: 'string', length: 160)]
    private string $displayName;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_access_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastAccessAt = null;

    /** @param list<string> $roles */
    public function __construct(string $email, string $displayName, array $roles = [])
    {
        $this->id = UlidFactory::new();
        $this->email = strtolower(trim($email));
        $this->displayName = trim($displayName);
        $this->roles = array_values(array_unique($roles));
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function lastAccessAt(): ?DateTimeImmutable
    {
        return $this->lastAccessAt;
    }

    public function markAccessedAt(?DateTimeImmutable $at = null): void
    {
        $this->lastAccessAt = $at
            ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function grantRole(string $role): void
    {
        $role = trim($role);
        if ($role === '' || $this->hasRole($role)) {
            return;
        }

        $this->roles[] = $role;
    }

    public function revokeRole(string $role): void
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            static fn (string $current): bool => $current !== $role,
        ));
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        $roles = $this->roles;
        $otherRoles = $user->roles;
        sort($roles);
        sort($otherRoles);

        return hash_equals($this->id, $user->id)
            && hash_equals($this->email, $user->email)
            && hash_equals($this->passwordHash, $user->passwordHash)
            && $this->active === $user->active
            && $roles === $otherRoles;
    }

    public function eraseCredentials(): void
    {
    }
}

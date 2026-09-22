<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class PlatformInvitationSecurity
{
    private const INVITATION_TTL = '+48 hours';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function assertOwner(User $actor): void
    {
        if (
            !$actor->isActive()
            || !$actor->hasRole(User::ROLE_PLATFORM_OWNER)
        ) {
            throw new AccessDeniedException();
        }
    }

    public function assertOwnerFresh(User $actor): void
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT active, roles FROM condor_user '
            .'WHERE id = :id FOR UPDATE',
            ['id' => $actor->id()],
        );
        if ($row === false) {
            throw new AccessDeniedException();
        }

        $roles = json_decode((string) $row['roles'], true);
        if (
            (int) $row['active'] !== 1
            || !is_array($roles)
            || !in_array(User::ROLE_PLATFORM_OWNER, $roles, true)
        ) {
            throw new AccessDeniedException();
        }
    }

    /** @return array{0: string, 1: string, 2: DateTimeImmutable} */
    public function issue(): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $now = $this->now();

        return [
            $rawToken,
            hash('sha256', $rawToken),
            $now->modify(self::INVITATION_TTL),
        ];
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

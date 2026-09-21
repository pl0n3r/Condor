<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Onboarding\ProvisionTenant;
use App\Application\Onboarding\ProvisionTenantInput;
use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class PlatformTenantManager
{
    private const INVITATION_TTL = '+48 hours';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProvisionTenant $provisionTenant,
    ) {
    }

    public function createAndInviteOwner(
        User $actor,
        ProvisionTenantInput $tenantInput,
        string $ownerEmail,
        string $ownerName,
    ): PlatformTenantInvitationResult {
        $this->assertOwnerFresh($actor);
        $email = strtolower(trim($ownerEmail));
        $ownerName = trim($ownerName);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(
                'El correo del administrador no es válido.',
            );
        }
        if ($ownerName === '' || mb_strlen($ownerName, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre del administrador es obligatorio y admite máximo 160 caracteres.',
            );
        }
        if (
            $this->entityManager
                ->getRepository(User::class)
                ->findOneBy(['email' => $email]) instanceof User
        ) {
            throw new DomainException(
                'Ya existe una cuenta con ese correo.',
            );
        }

        try {
            return $this->entityManager->wrapInTransaction(
                function () use (
                    $actor,
                    $tenantInput,
                    $email,
                    $ownerName,
                ): PlatformTenantInvitationResult {
                    $provisioning = $this->provisionTenant
                        ->prepare($tenantInput);

                    $owner = new User($email, $ownerName);
                    $owner->deactivate();
                    $membership = new Membership(
                        $provisioning->tenant,
                        $owner,
                        Membership::ROLE_OWNER,
                    );

                    [$rawToken, $tokenHash] = self::newToken();
                    $invitation = new AccountInvitation(
                        $owner,
                        $provisioning->tenant,
                        AccountInvitation::KIND_TENANT_MEMBER,
                        $tokenHash,
                        self::now()->modify(self::INVITATION_TTL),
                        $actor->id(),
                    );

                    foreach ([$owner, $membership, $invitation] as $entity) {
                        $this->entityManager->persist($entity);
                    }

                    $this->entityManager->persist(new PlatformAuditEvent(
                        $actor->id(),
                        $provisioning->tenant->id(),
                        'platform_tenant.created',
                        Tenant::class,
                        $provisioning->tenant->id(),
                        [
                            'branch_id' => $provisioning->branch->id(),
                            'owner_user_id' => $owner->id(),
                            'invitation_id' => $invitation->id(),
                        ],
                    ));
                    $this->entityManager->flush();

                    return new PlatformTenantInvitationResult(
                        $provisioning,
                        $owner,
                        $invitation,
                        $rawToken,
                    );
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'Los datos de la empresa o del administrador ya están registrados.',
                0,
                $exception,
            );
        }
    }

    private function assertOwnerFresh(User $actor): void
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

    /** @return array{0: string, 1: string} */
    private static function newToken(): array
    {
        $raw = bin2hex(random_bytes(32));

        return [$raw, hash('sha256', $raw)];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

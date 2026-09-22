<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Onboarding\ProvisionTenant;
use App\Application\Onboarding\ProvisionTenantInput;
use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use App\Infrastructure\Observability\FunctionalSignalRecorder;

final readonly class PlatformTenantManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProvisionTenant $provisionTenant,
        private PlatformInvitationSecurity $security,
        private FunctionalSignalRecorder $signals,
    ) {
    }

    public function createAndInviteOwner(
        User $actor,
        ProvisionTenantInput $tenantInput,
        string $ownerEmail,
        string $ownerName,
    ): PlatformTenantInvitationResult {
        $this->security->assertOwnerFresh($actor);
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
            $result = $this->entityManager->wrapInTransaction(
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

                    [$rawToken, $tokenHash, $expiresAt] = $this->security->issue();
                    $invitation = new AccountInvitation(
                        $owner,
                        $provisioning->tenant,
                        AccountInvitation::KIND_TENANT_MEMBER,
                        $tokenHash,
                        $expiresAt,
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

        $this->signals->record(
            FunctionalSignal::TENANT_CREATED,
            $result->provisioning->tenant->id(),
            ['source' => 'platform_owner'],
        );

        return $result;
    }
}

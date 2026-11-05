<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Async\OutboxDelivery;
use App\Application\Async\OutboxEnqueuer;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class ControlBotStaffManager
{
    private const MACHINE_ACTOR_ID = 'CONTROLBOT000000000000000';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformInvitationSecurity $invitationSecurity,
        private TransactionalEmailGateway $emailGateway,
        private OutboxEnqueuer $outbox,
        private OutboxDelivery $outboxDelivery,
    ) {
    }

    public function invite(
        string $email,
        string $displayName,
        string $role,
    ): PlatformStaffInvitationResult
    {
        if (!$this->emailGateway->isAvailable()) {
            throw new DomainException('La entrega de invitaciones de staff no está configurada.');
        }

        $email = strtolower(trim($email));
        $displayName = trim($displayName);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Correo de staff inválido.');
        }
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 160) {
            throw new DomainException('Nombre de staff inválido.');
        }
        if (trim($role) !== 'staff') {
            throw new DomainException('Rol ControlBot no permitido.');
        }
        if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
            throw new DomainException('Ya existe una cuenta con ese correo.');
        }

        $staff = new User($email, $displayName, [User::ROLE_PLATFORM_STAFF]);
        $staff->deactivate();
        [$rawToken, $tokenHash, $expiresAt] = $this->invitationSecurity->issue();
        $invitation = new AccountInvitation(
            $staff,
            null,
            AccountInvitation::KIND_PLATFORM_STAFF,
            $tokenHash,
            $expiresAt,
            self::MACHINE_ACTOR_ID,
        );
        $this->entityManager->persist($staff);
        $this->entityManager->persist($invitation);
        $this->enqueueInvitation($staff, $invitation, $rawToken);

        return new PlatformStaffInvitationResult($staff, $invitation, $rawToken);
    }

    /**
     * The envelope already exists (invite()/reissueInvitation() wrote it in the
     * caller's transaction). This attempts it now; on failure it is cancelled
     * and the exception lets the caller revoke the invitation.
     */
    public function deliverInvitation(PlatformStaffInvitationResult $result): void
    {
        $this->outboxDelivery->deliverNow(self::invitationKey($result->invitation, $result->rawToken));
    }

    private static function invitationKey(AccountInvitation $invitation, string $rawToken): string
    {
        return 'platform_staff_invitation:'.$invitation->id().':'.substr(hash('sha256', $rawToken), 0, 16);
    }

    private function enqueueInvitation(User $staff, AccountInvitation $invitation, string $rawToken): void
    {
        $this->outbox->enqueue(
            self::invitationKey($invitation, $rawToken),
            'transactional_email',
            [
                'recipient' => $staff->email(),
                'template' => 'platform_staff_invitation',
                'data' => [
                    'display_name' => $staff->displayName(),
                    'activation_token' => $rawToken,
                    'expires_at' => $invitation->expiresAt()->format(DATE_ATOM),
                ],
            ],
        );
    }

    public function revokeInvitation(PlatformStaffInvitationResult $result): void
    {
        $result->invitation->revoke(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->entityManager->flush();
    }

    public function reissueInvitation(User $staff): PlatformStaffInvitationResult
    {
        $this->assertMutableStaff($staff);
        $invitation = $this->entityManager
            ->getRepository(AccountInvitation::class)
            ->findOneBy(['user' => $staff]);
        if (!$invitation instanceof AccountInvitation) {
            throw new DomainException('Invitación de staff pendiente no encontrada.');
        }

        [$rawToken, $tokenHash, $expiresAt] = $this->invitationSecurity->issue();
        $invitation->reissue($tokenHash, $expiresAt);
        $this->enqueueInvitation($staff, $invitation, $rawToken);

        return new PlatformStaffInvitationResult($staff, $invitation, $rawToken);
    }

    public function suspend(User $staff): void
    {
        $this->assertMutableStaff($staff);
        $staff->deactivate();
    }

    public function reactivate(User $staff): void
    {
        $this->assertMutableStaff($staff);
        if ($staff->getPassword() === '') {
            throw new DomainException('Una cuenta invitada debe activarse mediante su invitación.');
        }
        $invitation = $this->entityManager
            ->getRepository(AccountInvitation::class)
            ->findOneBy(['user' => $staff]);
        if ($invitation instanceof AccountInvitation
            && $invitation->isUsableAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            throw new DomainException('Una cuenta invitada debe activarse mediante su invitación.');
        }
        $staff->activate();
    }

    public function setRole(User $staff, string $role): void
    {
        $this->assertMutableStaff($staff);
        if (trim($role) !== 'staff') {
            throw new DomainException('Rol ControlBot no permitido.');
        }
        $staff->grantRole(User::ROLE_PLATFORM_STAFF);
    }

    public function assertMutableStaff(User $user): void
    {
        if (
            $user->hasRole(User::ROLE_PLATFORM_OWNER)
            || $user->hasRole(User::ROLE_LEGACY_SUPER_ADMIN)
            || !$user->hasRole(User::ROLE_PLATFORM_STAFF)
        ) {
            throw new DomainException('Cuenta fuera del dominio mutable de ControlBot.');
        }
    }
}

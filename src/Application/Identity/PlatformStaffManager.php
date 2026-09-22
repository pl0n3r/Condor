<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\PlatformStaffGrant;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlatformStaffManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformInvitationSecurity $security,
    ) {
    }

    /**
     * @param list<array{
     *   tenant_id: string|null,
     *   module: string,
     *   actions: array<mixed>
     * }> $definitions
     */
    public function invite(
        User $actor,
        string $email,
        string $displayName,
        array $definitions,
    ): PlatformStaffInvitationResult {
        return $this->entityManager->wrapInTransaction(
            function () use ($actor, $email, $displayName, $definitions): PlatformStaffInvitationResult {
                $this->security->assertOwnerFresh($actor);
                $email = strtolower(trim($email));
                $displayName = trim($displayName);

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new DomainException('El correo del staff no es válido.');
                }
                if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 160) {
                    throw new DomainException(
                        'El nombre del staff es obligatorio y admite máximo 160 caracteres.',
                    );
                }

                $existing = $this->entityManager
                    ->getRepository(User::class)
                    ->findOneBy(['email' => $email]);
                if ($existing instanceof User) {
                    throw new DomainException('Ya existe una cuenta con ese correo.');
                }

                $staff = new User(
                    $email,
                    $displayName,
                    [User::ROLE_PLATFORM_STAFF],
                );
                $staff->deactivate();
                $this->entityManager->persist($staff);

                $grants = $this->buildGrants($staff, $definitions);
                foreach ($grants as $grant) {
                    $this->entityManager->persist($grant);
                }

                [$rawToken, $tokenHash, $expiresAt] = $this->security->issue();
                $invitation = new AccountInvitation(
                    $staff,
                    null,
                    AccountInvitation::KIND_PLATFORM_STAFF,
                    $tokenHash,
                    $expiresAt,
                    $actor->id(),
                );
                $this->entityManager->persist($invitation);
                $this->entityManager->persist(new PlatformAuditEvent(
                    $actor->id(),
                    null,
                    'platform_staff.invited',
                    User::class,
                    $staff->id(),
                    ['grant_count' => count($grants)],
                ));
                $this->entityManager->flush();

                return new PlatformStaffInvitationResult(
                    $staff,
                    $invitation,
                    $rawToken,
                );
            },
        );
    }

    /**
     * @param list<array{
     *   tenant_id: string|null,
     *   module: string,
     *   actions: array<mixed>
     * }> $definitions
     * @return list<PlatformStaffGrant>
     */
    public function replaceGrants(
        User $actor,
        User $staff,
        array $definitions,
    ): array {
        return $this->entityManager->wrapInTransaction(
            function () use ($actor, $staff, $definitions): array {
                $this->security->assertOwnerFresh($actor);
                $this->assertStaffFresh($staff);

                $repository = $this->entityManager
                    ->getRepository(PlatformStaffGrant::class);
                foreach ($repository->findBy(['staff' => $staff]) as $existing) {
                    if ($existing instanceof PlatformStaffGrant) {
                        $this->entityManager->remove($existing);
                    }
                }

                $grants = $this->buildGrants($staff, $definitions);
                foreach ($grants as $grant) {
                    $this->entityManager->persist($grant);
                }

                $this->entityManager->persist(new PlatformAuditEvent(
                    $actor->id(),
                    null,
                    'platform_staff.grants_replaced',
                    User::class,
                    $staff->id(),
                    ['grant_count' => count($grants)],
                ));
                $this->entityManager->flush();

                return $grants;
            },
        );
    }

    public function reissueInvitation(
        User $actor,
        User $staff,
    ): PlatformStaffInvitationResult {
        return $this->entityManager->wrapInTransaction(
            function () use ($actor, $staff): PlatformStaffInvitationResult {
                $this->security->assertOwnerFresh($actor);
                $this->assertStaffFresh($staff);
                if ($staff->isActive()) {
                    throw new DomainException(
                        'La cuenta ya está activa y no requiere invitación.',
                    );
                }

                $invitation = $this->invitationFor($staff);
                $this->lockInvitation($invitation);
                [$rawToken, $tokenHash, $expiresAt] = $this->security->issue();
                $invitation->reissue($tokenHash, $expiresAt);
                $this->entityManager->persist(new PlatformAuditEvent(
                    $actor->id(),
                    null,
                    'platform_staff.invitation_reissued',
                    AccountInvitation::class,
                    $invitation->id(),
                    ['staff_user_id' => $staff->id()],
                ));
                $this->entityManager->flush();

                return new PlatformStaffInvitationResult(
                    $staff,
                    $invitation,
                    $rawToken,
                );
            },
        );
    }

    public function revokeInvitation(User $actor, User $staff): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($actor, $staff): void {
                $this->security->assertOwnerFresh($actor);
                $this->assertStaffFresh($staff);
                $invitation = $this->invitationFor($staff);
                $invitation->revoke($this->security->now());
                $this->entityManager->persist(new PlatformAuditEvent(
                    $actor->id(),
                    null,
                    'platform_staff.invitation_revoked',
                    AccountInvitation::class,
                    $invitation->id(),
                    ['staff_user_id' => $staff->id()],
                ));
                $this->entityManager->flush();
            },
        );
    }

    /** @return list<array<string, mixed>> */
    public function overview(User $actor): array
    {
        $this->security->assertOwner($actor);

        $invitations = $this->entityManager
            ->getRepository(AccountInvitation::class)
            ->findBy(
                ['kind' => AccountInvitation::KIND_PLATFORM_STAFF],
                ['id' => 'ASC'],
            );
        $rows = [];
        foreach ($invitations as $invitation) {
            if (!$invitation instanceof AccountInvitation) {
                continue;
            }

            $staff = $invitation->user();
            $rows[] = [
                'id' => $staff->id(),
                'name' => $staff->displayName(),
                'email' => $staff->email(),
                'active' => $staff->isActive(),
                'invitation' => [
                    'id' => $invitation->id(),
                    'state' => $this->invitationState($invitation),
                    'expires_at' => $invitation->expiresAt()->format(DATE_ATOM),
                ],
                'grants' => $this->grantPayloads($staff),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['name'],
                (string) $right['name'],
            ),
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function grantPayloads(User $staff): array
    {
        $this->assertStaff($staff);
        $grants = $this->entityManager
            ->getRepository(PlatformStaffGrant::class)
            ->findBy(
                ['staff' => $staff],
                ['scopeKey' => 'ASC', 'moduleKey' => 'ASC'],
            );

        return array_values(array_map(
            static fn (PlatformStaffGrant $grant): array => self::grantPayload($grant),
            array_values(array_filter(
                $grants,
                static fn (mixed $grant): bool => $grant instanceof PlatformStaffGrant,
            )),
        ));
    }

    /**
     * @param list<array{
     *   tenant_id: string|null,
     *   module: string,
     *   actions: array<mixed>
     * }> $definitions
     * @return list<PlatformStaffGrant>
     */
    private function buildGrants(User $staff, array $definitions): array
    {
        if ($definitions === []) {
            throw new DomainException(
                'Asigna al menos un permiso al staff de plataforma.',
            );
        }

        $grants = [];
        $seen = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new DomainException(
                    'Cada permiso de staff debe ser un objeto.',
                );
            }

            $keys = array_keys($definition);
            sort($keys, SORT_STRING);
            if ($keys !== ['actions', 'module', 'tenant_id']) {
                throw new DomainException(
                    'Cada permiso requiere únicamente tenant_id, module y actions.',
                );
            }

            $tenantId = $definition['tenant_id'];
            $tenant = null;
            if ($tenantId !== null) {
                if (!is_string($tenantId) || trim($tenantId) === '') {
                    throw new DomainException(
                        'El tenant del permiso no es válido.',
                    );
                }

                $tenant = $this->entityManager
                    ->getRepository(Tenant::class)
                    ->find($tenantId);
                if (!$tenant instanceof Tenant) {
                    throw new DomainException(
                        'La empresa indicada no existe.',
                    );
                }
            }

            $module = PermissionCatalog::normalizeModule(
                (string) $definition['module'],
            );
            $actions = is_array($definition['actions'])
                ? $definition['actions']
                : [];
            $scope = $tenant?->id() ?? '*';
            $key = $scope.'|'.$module;
            if (isset($seen[$key])) {
                throw new DomainException(
                    'No repitas el mismo módulo dentro del mismo alcance.',
                );
            }
            $seen[$key] = true;

            $grants[] = new PlatformStaffGrant(
                $staff,
                $tenant,
                $module,
                $actions,
            );
        }

        return $grants;
    }

    private function invitationFor(User $staff): AccountInvitation
    {
        $invitation = $this->entityManager
            ->getRepository(AccountInvitation::class)
            ->findOneBy([
                'user' => $staff,
                'kind' => AccountInvitation::KIND_PLATFORM_STAFF,
            ]);
        if (!$invitation instanceof AccountInvitation) {
            throw new DomainException(
                'La cuenta no tiene una invitación de staff.',
            );
        }

        return $invitation;
    }

    private function lockInvitation(AccountInvitation $invitation): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM condor_account_invitation '
            .'WHERE id = :id FOR UPDATE',
            ['id' => $invitation->id()],
        )->fetchOne();
        $this->entityManager->refresh($invitation);
    }

    private function assertStaffFresh(User $staff): void
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT roles FROM condor_user WHERE id = :id FOR UPDATE',
            ['id' => $staff->id()],
        );
        if ($row === false) {
            throw new DomainException('La cuenta de staff no existe.');
        }

        $roles = json_decode((string) $row['roles'], true);
        if (
            !is_array($roles)
            || !in_array(User::ROLE_PLATFORM_STAFF, $roles, true)
        ) {
            throw new DomainException(
                'La cuenta no pertenece al staff de plataforma.',
            );
        }
    }

    private function assertStaff(User $staff): void
    {
        if (!$staff->hasRole(User::ROLE_PLATFORM_STAFF)) {
            throw new DomainException(
                'La cuenta no pertenece al staff de plataforma.',
            );
        }
    }

    private function invitationState(
        AccountInvitation $invitation,
    ): string {
        if ($invitation->user()->isActive()) {
            return 'active';
        }
        if ($invitation->consumedAt() !== null) {
            return 'consumed';
        }
        if ($invitation->revokedAt() !== null) {
            return 'revoked';
        }
        if ($invitation->expiresAt() <= $this->security->now()) {
            return 'expired';
        }

        return 'pending';
    }

    /**
     * @return array{
     *   tenant_id: string|null,
     *   module: string,
     *   actions: list<string>
     * }
     */
    private static function grantPayload(
        PlatformStaffGrant $grant,
    ): array {
        return [
            'tenant_id' => $grant->tenant()?->id(),
            'module' => $grant->moduleKey(),
            'actions' => $grant->actions(),
        ];
    }
}

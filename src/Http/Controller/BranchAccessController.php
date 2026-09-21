<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BranchAccessController extends AbstractController
{
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/roles', name: 'api_branch_roles', methods: ['GET'])]
    public function roles(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedScope($branchId, 'roles.view');

        $roles = $this->entityManager->getRepository(Role::class)->findBy(
            ['tenant' => $tenant, 'active' => true],
            ['name' => 'ASC'],
        );

        return $this->json([
            'roles' => array_map(
                static fn (Role $role): array => self::rolePayload($role),
                array_values(array_filter(
                    $roles,
                    static fn (mixed $role): bool => $role instanceof Role,
                )),
            ),
            'catalog' => PermissionCatalog::definitions(),
        ]);
    }

    #[Route('/api/v1/branches/{branchId}/roles', name: 'api_branch_roles_create', methods: ['POST'])]
    public function createRole(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $actor] = $this->authorizedScope(
            $branchId,
            'roles.create',
        );
        $payload = $this->payload($request);
        $permissions = $this->permissionsFromPayload(
            $payload['permissions'] ?? [],
        );
        $this->assertDelegable(
            $actor,
            $this->authorization->permissions($user, $tenant, $branch),
            $permissions,
        );

        $role = $this->domain(
            static fn (): Role => new Role(
                $tenant,
                (string) ($payload['name'] ?? ''),
                $permissions,
            ),
        );
        $this->entityManager->persist($role);
        $this->audit(
            $tenant,
            $user,
            'role.created',
            Role::class,
            $role->id(),
            [
                'branch_id' => $branch->id(),
                'permissions' => $role->permissions(),
            ],
        );
        $this->flushRoleChange();

        return $this->json(
            ['role' => self::rolePayload($role)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/v1/branches/{branchId}/roles/{roleId}', name: 'api_branch_roles_update', methods: ['PATCH'])]
    public function updateRole(
        string $branchId,
        string $roleId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $actor] = $this->authorizedScope(
            $branchId,
            'roles.update',
        );
        $role = $this->role($roleId, $tenant);
        $actorPermissions = $this->authorization->permissions(
            $user,
            $tenant,
            $branch,
        );
        $this->assertDelegable(
            $actor,
            $actorPermissions,
            $role->permissions(),
        );

        $payload = $this->payload($request);
        $permissions = $this->permissionsFromPayload(
            $payload['permissions'] ?? $role->permissions(),
        );
        $this->assertDelegable(
            $actor,
            $actorPermissions,
            $permissions,
        );

        $this->domain(
            static function () use ($role, $payload, $permissions): null {
                $role->update(
                    (string) ($payload['name'] ?? $role->name()),
                    $permissions,
                );

                return null;
            },
        );
        $this->audit(
            $tenant,
            $user,
            'role.updated',
            Role::class,
            $role->id(),
            [
                'branch_id' => $branch->id(),
                'permissions' => $role->permissions(),
            ],
        );
        $this->flushRoleChange();

        return $this->json(['role' => self::rolePayload($role)]);
    }

    #[Route('/api/v1/branches/{branchId}/roles/{roleId}', name: 'api_branch_roles_delete', methods: ['DELETE'])]
    public function deleteRole(
        string $branchId,
        string $roleId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $actor] = $this->authorizedScope(
            $branchId,
            'roles.delete',
        );
        $role = $this->role($roleId, $tenant);
        $this->assertDelegable(
            $actor,
            $this->authorization->permissions($user, $tenant, $branch),
            $role->permissions(),
        );

        $role->deactivate();
        $this->audit(
            $tenant,
            $user,
            'role.deactivated',
            Role::class,
            $role->id(),
            ['branch_id' => $branch->id()],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/branches/{branchId}/memberships', name: 'api_branch_memberships', methods: ['GET'])]
    public function memberships(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'users.view',
        );

        $memberships = $this->entityManager
            ->getRepository(Membership::class)
            ->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['id' => 'ASC'],
            );
        $assignments = $this->entityManager
            ->getRepository(BranchRoleAssignment::class)
            ->findBy([
                'tenant' => $tenant,
                'branch' => $branch,
            ]);
        $roleIds = [];
        foreach ($assignments as $assignment) {
            if (
                !$assignment instanceof BranchRoleAssignment
                || !$assignment->role()->isActive()
            ) {
                continue;
            }

            $roleIds[$assignment->membership()->id()][] = (
                $assignment->role()->id()
            );
        }

        return $this->json([
            'memberships' => $this->membershipPayloads(
                $memberships,
                $roleIds,
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/memberships/{membershipId}/roles/{roleId}',
        name: 'api_branch_membership_role_assign',
        methods: ['PUT'],
    )]
    public function assignRole(
        string $branchId,
        string $membershipId,
        string $roleId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $actor] = $this->authorizedScope(
            $branchId,
            'users.update',
        );
        $target = $this->membership($membershipId, $tenant);
        $role = $this->role($roleId, $tenant);
        $this->assertDelegable(
            $actor,
            $this->authorization->permissions($user, $tenant, $branch),
            $role->permissions(),
        );

        $repository = $this->entityManager->getRepository(
            BranchRoleAssignment::class,
        );
        $existing = $repository->findOneBy([
            'tenant' => $tenant,
            'membership' => $target,
            'branch' => $branch,
            'role' => $role,
        ]);
        if (!$existing instanceof BranchRoleAssignment) {
            $assignment = new BranchRoleAssignment($target, $branch, $role);
            $this->entityManager->persist($assignment);
            $this->audit(
                $tenant,
                $user,
                'branch_role.assigned',
                BranchRoleAssignment::class,
                $assignment->id(),
                $this->assignmentContext($branch, $target, $role),
            );
            $this->entityManager->flush();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/v1/branches/{branchId}/memberships/{membershipId}/roles/{roleId}',
        name: 'api_branch_membership_role_unassign',
        methods: ['DELETE'],
    )]
    public function unassignRole(
        string $branchId,
        string $membershipId,
        string $roleId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $actor] = $this->authorizedScope(
            $branchId,
            'users.update',
        );
        $target = $this->membership($membershipId, $tenant);
        $role = $this->role($roleId, $tenant);
        $this->assertDelegable(
            $actor,
            $this->authorization->permissions($user, $tenant, $branch),
            $role->permissions(),
        );

        $assignment = $this->entityManager
            ->getRepository(BranchRoleAssignment::class)
            ->findOneBy([
                'tenant' => $tenant,
                'membership' => $target,
                'branch' => $branch,
                'role' => $role,
            ]);

        if ($assignment instanceof BranchRoleAssignment) {
            $assignmentId = $assignment->id();
            $this->entityManager->remove($assignment);
            $this->audit(
                $tenant,
                $user,
                'branch_role.unassigned',
                BranchRoleAssignment::class,
                $assignmentId,
                $this->assignmentContext($branch, $target, $role),
            );
            $this->entityManager->flush();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Branch, 3: Membership}
     */
    private function authorizedScope(
        string $branchId,
        string $permission,
    ): array {
        [$user, $tenant, $branch] = $this->scope($branchId);
        $membership = $this->authorization->require(
            $user,
            $tenant,
            $branch,
            $permission,
        );

        return [$user, $tenant, $branch, $membership];
    }

    /** @return array{0: User, 1: Tenant, 2: Branch} */
    private function scope(string $branchId): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        try {
            $tenant = $this->currentTenantForUser->resolve($user);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        $branch = $this->entityManager->getRepository(Branch::class)->findOneBy([
            'id' => $branchId,
            'tenant' => $tenant,
        ]);
        if (!$branch instanceof Branch) {
            throw new NotFoundHttpException('Sede no encontrada.');
        }

        return [$user, $tenant, $branch];
    }

    private function role(string $roleId, Tenant $tenant): Role
    {
        $role = $this->entityManager->getRepository(Role::class)->findOneBy([
            'id' => $roleId,
            'tenant' => $tenant,
            'active' => true,
        ]);
        if (!$role instanceof Role) {
            throw new NotFoundHttpException('Rol no encontrado.');
        }

        return $role;
    }

    private function membership(
        string $membershipId,
        Tenant $tenant,
    ): Membership {
        $membership = $this->entityManager
            ->getRepository(Membership::class)
            ->findOneBy([
                'id' => $membershipId,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$membership instanceof Membership) {
            throw new NotFoundHttpException('Membresía no encontrada.');
        }

        return $membership;
    }

    private function requireCsrf(Request $request): void
    {
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('branch_access', $token)) {
            throw new AccessDeniedHttpException('Token CSRF inválido.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        try {
            $payload = json_decode(
                (string) $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException(
                'El cuerpo debe ser un objeto JSON.',
            );
        }

        return $payload;
    }

    /** @return list<string> */
    private function permissionsFromPayload(mixed $permissions): array
    {
        if (!is_array($permissions)) {
            throw new UnprocessableEntityHttpException(
                'Los permisos deben enviarse como una lista.',
            );
        }

        return $this->domain(
            static fn (): array => PermissionCatalog::normalize($permissions),
        );
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function domain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    private function flushRoleChange(): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException(
                'Ya existe un rol con ese nombre en la empresa.',
                $exception,
            );
        }
    }

    /** @param list<string> $actorPermissions @param list<string> $requested */
    private function assertDelegable(
        Membership $actor,
        array $actorPermissions,
        array $requested,
    ): void {
        if ($actor->roleKey() === Membership::ROLE_OWNER) {
            return;
        }

        foreach ($requested as $permission) {
            if (!in_array($permission, $actorPermissions, true)) {
                throw new AccessDeniedHttpException(
                    'No puedes delegar permisos que no posees.',
                );
            }
        }
    }

    /**
     * @param list<Membership|mixed> $memberships
     * @param array<string, list<string>> $roleIds
     * @return list<array<string, mixed>>
     */
    private function membershipPayloads(
        array $memberships,
        array $roleIds,
    ): array {
        $result = [];

        foreach ($memberships as $membership) {
            if (!$membership instanceof Membership) {
                continue;
            }

            $memberUser = $membership->user();
            $assigned = $roleIds[$membership->id()] ?? [];
            sort($assigned, SORT_STRING);
            $result[] = [
                'id' => $membership->id(),
                'user' => [
                    'id' => $memberUser->id(),
                    'name' => $memberUser->displayName(),
                    'email' => $memberUser->email(),
                ],
                'tenant_owner' => (
                    $membership->roleKey() === Membership::ROLE_OWNER
                ),
                'role_ids' => array_values($assigned),
            ];
        }

        return $result;
    }

    /** @return array{branch_id: string, membership_id: string, role_id: string} */
    private function assignmentContext(
        Branch $branch,
        Membership $membership,
        Role $role,
    ): array {
        return [
            'branch_id' => $branch->id(),
            'membership_id' => $membership->id(),
            'role_id' => $role->id(),
        ];
    }

    private function audit(
        Tenant $tenant,
        User $actor,
        string $action,
        string $entityType,
        string $entityId,
        array $context,
    ): void {
        $this->entityManager->persist(new AuditEvent(
            $tenant,
            $actor->id(),
            $action,
            $entityType,
            $entityId,
            $context,
        ));
    }

    /** @return array{id: string, name: string, permissions: list<string>} */
    private static function rolePayload(Role $role): array
    {
        return [
            'id' => $role->id(),
            'name' => $role->name(),
            'permissions' => $role->permissions(),
        ];
    }
}

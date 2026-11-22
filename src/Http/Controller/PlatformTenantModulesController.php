<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Platform\ModuleAccess;
use App\Application\Platform\ModuleCatalog;
use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Persistence\ModuleAccessRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Owner-only, tenant-bound and audited. Individual module updates are batched
 * in one transaction per section; no partial grants on an invalid user or key.
 */
#[Route('/adminpl0n3r/api/tenants/{tenantId}/modules')]
final class PlatformTenantModulesController extends PlatformOwnerApiController
{
    private const CSRF = 'platform_tenant_management';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly ModuleAccessRepository $repository,
        private readonly ModuleAccess $access,
        private readonly CsrfTokenManagerInterface $tokens,
    ) {
    }

    #[Route('', name: 'platform_tenant_modules_index', methods: ['GET'])]
    public function index(string $tenantId): JsonResponse
    {
        $this->platformOwner();
        $tenant = $this->tenant($tenantId);
        $overrides = $this->access->tenantOverrides($tenant->id());
        $defaults = $this->access->planDefaults($tenant->id());
        $modules = [];
        foreach (ModuleCatalog::all() as $module) {
            $key = $module['key'];
            $modules[] = [
                ...$module,
                'plan_default' => $defaults[$key] ?? true,
                'tenant_override' => $overrides[$key] ?? null,
                'tenant_enabled' => $overrides[$key] ?? $defaults[$key] ?? true,
            ];
        }

        $users = [];
        foreach ($this->memberships($tenant) as $membership) {
            $user = $membership->user();
            $userOverrides = $this->access->userOverrides($tenant->id(), $user->id());
            $effective = [];
            foreach ($modules as $module) {
                $key = $module['key'];
                $effective[$key] = $module['tenant_enabled'] && ($userOverrides[$key] ?? true);
            }
            $users[] = [
                'id' => $user->id(),
                'name' => $user->displayName(),
                'email' => $user->email(),
                'enabled' => $effective,
                'overrides' => $userOverrides,
            ];
        }

        return $this->privateJson([
            'tenant' => ['id' => $tenant->id(), 'name' => $tenant->name()],
            'modules' => $modules,
            'users' => $users,
            'csrf_token' => (string) $this->tokens->getToken(self::CSRF)->getValue(),
        ]);
    }

    #[Route('', name: 'platform_tenant_modules_update', methods: ['PUT'])]
    public function updateTenant(string $tenantId, Request $request): JsonResponse
    {
        $actor = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF);
        $tenant = $this->tenant($tenantId);
        $payload = $this->jsonPayload($request);
        if (array_keys($payload) !== ['changes']) {
            throw new BadRequestHttpException('Solo se permiten cambios de módulos.');
        }
        $changes = $this->parseChanges($payload['changes']);

        $this->connection->transactional(function () use ($tenant, $actor, $changes): void {
            foreach ($changes as $change) {
                $this->repository->setTenant($tenant->id(), $change['key'], $change['enabled'], $actor->id());
                $this->entityManager->persist(new PlatformAuditEvent(
                    $actor->id(), $tenant->id(), 'owner.module.tenant.updated',
                    Tenant::class, $tenant->id(),
                    ['module' => $change['key'], 'enabled' => $change['enabled']],
                ));
            }
            $this->entityManager->flush();
        });
        $this->access->invalidate($tenant->id());

        return $this->privateJson(['saved' => count($changes)]);
    }

    #[Route('/users', name: 'platform_tenant_user_modules_update', methods: ['PUT'])]
    public function updateUsers(string $tenantId, Request $request): JsonResponse
    {
        $actor = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF);
        $tenant = $this->tenant($tenantId);
        $payload = $this->jsonPayload($request);
        if (array_keys($payload) !== ['changes']) {
            throw new BadRequestHttpException('Solo se permiten cambios de acceso por usuario.');
        }
        $changes = $payload['changes'];
        if (!is_array($changes) || !array_is_list($changes)
            || $changes === [] || count($changes) > 300) {
            throw new UnprocessableEntityHttpException('La lista de accesos es inválida.');
        }

        $allowedUsers = [];
        foreach ($this->memberships($tenant) as $membership) {
            $allowedUsers[$membership->user()->id()] = true;
        }
        $valid = [];
        foreach ($changes as $item) {
            if (!is_array($item)
                || count($item) !== 3
                || !is_string($item['user_id'] ?? null)
                || !isset($allowedUsers[$item['user_id']])
                || !is_string($item['key'] ?? null)
                || !ModuleCatalog::exists($item['key'])
                || !is_bool($item['enabled'] ?? null)) {
                throw new UnprocessableEntityHttpException('Usuario o módulo fuera de alcance.');
            }
            $unique = $item['user_id'].':'.$item['key'];
            if (isset($valid[$unique])) {
                throw new UnprocessableEntityHttpException('Cambio duplicado.');
            }
            $valid[$unique] = $item;
        }

        $this->connection->transactional(function () use ($tenant, $actor, $valid): void {
            foreach ($valid as $item) {
                $this->repository->setUser(
                    $tenant->id(), $item['user_id'], $item['key'], $item['enabled'], $actor->id(),
                );
                $this->entityManager->persist(new PlatformAuditEvent(
                    $actor->id(), $tenant->id(), 'owner.module.user.updated',
                    User::class, $item['user_id'],
                    ['module' => $item['key'], 'enabled' => $item['enabled']],
                ));
            }
            $this->entityManager->flush();
        });
        foreach ($valid as $item) {
            $this->access->invalidate($tenant->id(), $item['user_id']);
        }

        return $this->privateJson(['saved' => count($valid)]);
    }

    /** @return list<array{key:string,enabled:bool}> */
    private function parseChanges(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items)
            || $items === [] || count($items) > count(ModuleCatalog::all())) {
            throw new UnprocessableEntityHttpException('Lista de módulos inválida.');
        }
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== 2
                || !is_string($item['key'] ?? null)
                || !ModuleCatalog::exists($item['key'])
                || !is_bool($item['enabled'] ?? null)
                || isset($seen[$item['key']])) {
                throw new UnprocessableEntityHttpException('Módulo inválido o duplicado.');
            }
            $seen[$item['key']] = true;
            $result[] = ['key' => $item['key'], 'enabled' => $item['enabled']];
        }

        return $result;
    }

    /** @return list<Membership> */
    private function memberships(Tenant $tenant): array
    {
        $result = [];
        foreach ($this->entityManager->getRepository(Membership::class)
            ->findBy(['tenant' => $tenant, 'active' => true]) as $membership) {
            if ($membership->user()->isActive()) {
                $result[] = $membership;
            }
        }

        return $result;
    }

    private function tenant(string $tenantId): Tenant
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $tenantId) !== 1) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }
        $tenant = $this->entityManager->getRepository(Tenant::class)->find($tenantId);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }

        return $tenant;
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

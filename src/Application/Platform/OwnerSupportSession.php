<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * An explicit, actor-bound, eight-hour support session. Never grants a
 * persistent membership or replaces the real authenticated principal.
 */
final readonly class OwnerSupportSession
{
    private const KEY = 'condor.platform_owner_support';
    private const TTL_SECONDS = 8 * 60 * 60;

    public function __construct(
        private RequestStack $requests,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokens,
    ) {
    }

    public function start(User $actor, Tenant $tenant, ?Branch $branch = null): void
    {
        $this->assertOwner($actor);
        if ($branch !== null && $branch->tenant()->id() !== $tenant->id()) {
            throw new AccessDeniedException('La sede no pertenece a la empresa solicitada.');
        }

        $request = $this->requests->getCurrentRequest()
            ?? throw new AccessDeniedException('No hay una petición activa.');
        $session = $request->getSession();
        $this->stop($actor);
        $session->migrate(true);
        $session->set(self::KEY, [
            'actor_id' => $actor->id(),
            'tenant_id' => $tenant->id(),
            'branch_id' => $branch?->id(),
            'expires_at' => time() + self::TTL_SECONDS,
        ]);
        $this->record($actor, $tenant, 'owner.support.started', [
            'branch_id' => $branch?->id(),
        ]);
    }

    /**
     * @return array{tenant: Tenant, branch: Branch|null}|null
     */
    public function current(User $actor): ?array
    {
        if (!$actor->isActive() || !$actor->hasRole(User::ROLE_PLATFORM_OWNER)) {
            return null;
        }

        $request = $this->requests->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $raw = $session->get(self::KEY);
        if (!is_array($raw)) {
            return null;
        }
        if (
            ($raw['actor_id'] ?? null) !== $actor->id()
            || !is_string($raw['tenant_id'] ?? null)
            || !is_int($raw['expires_at'] ?? null)
            || $raw['expires_at'] <= time()
        ) {
            $session->remove(self::KEY);

            return null;
        }

        $tenant = $this->entityManager->getRepository(Tenant::class)
            ->find($raw['tenant_id']);
        if (!$tenant instanceof Tenant) {
            $session->remove(self::KEY);

            return null;
        }

        $branch = null;
        if (isset($raw['branch_id'])) {
            if (!is_string($raw['branch_id'])) {
                $session->remove(self::KEY);

                return null;
            }
            $branch = $this->entityManager->getRepository(Branch::class)
                ->findOneBy(['id' => $raw['branch_id'], 'tenant' => $tenant]);
            if (!$branch instanceof Branch) {
                $session->remove(self::KEY);

                return null;
            }
        }

        return ['tenant' => $tenant, 'branch' => $branch];
    }

    public function tenantFor(User $actor): ?Tenant
    {
        return $this->current($actor)['tenant'] ?? null;
    }

    public function stop(User $actor): void
    {
        $this->assertOwner($actor);
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $active = $this->current($actor);
        $session = $request->getSession();
        $session->remove(self::KEY);
        if ($active === null) {
            return;
        }
        $session->migrate(true);
        $this->record($actor, $active['tenant'], 'owner.support.ended', [
            'branch_id' => $active['branch']?->id(),
        ]);
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: -16)]
    public function chooseInitialBranch(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/api/v1/context'
            || $request->getMethod() !== 'GET'
            || $request->query->has('branch')) {
            return;
        }
        $actor = $this->tokens->getToken()?->getUser();
        if (!$actor instanceof User) {
            return;
        }
        $branch = $this->current($actor)['branch'] ?? null;
        if ($branch instanceof Branch) {
            $request->query->set('branch', $branch->id());
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function auditWrite(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (
            !str_starts_with($request->getPathInfo(), '/api/v1/')
            || !in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $event->getResponse()->getStatusCode() >= 400
        ) {
            return;
        }
        $actor = $this->tokens->getToken()?->getUser();
        if (!$actor instanceof User) {
            return;
        }
        $active = $this->current($actor);
        if ($active === null) {
            return;
        }

        $route = (string) $request->attributes->get('_route', 'unknown');
        // Never persist request bodies, tokens, credentials or query parameters.
        $this->record($actor, $active['tenant'], 'owner.support.write', [
            'method' => $request->getMethod(),
            'route' => substr($route, 0, 100),
            'status' => $event->getResponse()->getStatusCode(),
            'branch_id' => $active['branch']?->id(),
        ]);
    }

    /** @param array<string, scalar|null> $context */
    private function record(
        User $actor,
        Tenant $tenant,
        string $action,
        array $context,
    ): void {
        $this->entityManager->persist(new PlatformAuditEvent(
            $actor->id(),
            $tenant->id(),
            $action,
            Tenant::class,
            $tenant->id(),
            $context,
        ));
        $this->entityManager->flush();
    }

    private function assertOwner(User $actor): void
    {
        if (!$actor->isActive() || !$actor->hasRole(User::ROLE_PLATFORM_OWNER)) {
            throw new AccessDeniedException('Solo el propietario puede administrar sesiones de soporte.');
        }
    }
}
